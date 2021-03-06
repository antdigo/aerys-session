<?php

namespace Aerys;

use Aerys\Session\Driver;
use Aerys\Session\LockException;
use Amp\Deferred;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

class Session {
    const CONFIG = [
        "name" => "session",
        "ttl" => -1,
        "maxlife" => 3600,
        "path" => "/",
    ];

    /** @var Driver driver */
    private $driver;
    private $request;
    private $id; // usually _the id_, false when expired (empty session data), null when not set at all
    private $data = [];
    private $state = self::UNLOCKED;
    private $ttl;
    private $maxlife;

    const ALLOWED_ID_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    const ID_BYTES = 24; // divisible by three to not waste chars with "="
    const ID_LENGTH = self::ID_BYTES * 4 / 3;

    const UNLOCKED = 0;
    const LOCKED = 1;
    const PENDING = 2;

    public function __construct(Request $request) {
        $this->request = $request;
        $config = $request->getLocalVar("aerys.session.config");
        assert(\is_array($config), 'No middleware was loaded or Aerys\Session class instantiated in invalid context');
        $this->driver = $config["driver"];

        $config += static::CONFIG;
        $request->setLocalVar("aerys.session.config", $config);

        $id = $request->getCookie($config["name"]);

        if (\strlen($id) === self::ID_LENGTH && strspn($id, self::ALLOWED_ID_CHARS) === self::ID_LENGTH) {
            $this->setId($id);
        }

        $this->ttl = $config["ttl"];
        $this->maxlife = $config["maxlife"];
    }


    private function generateId() {
        return base64_encode(random_bytes(self::ID_BYTES));
    }

    private function setId($id) {
        $this->id = $id;
        $this->request->setLocalVar("aerys.session.id", $id);
    }

    /**
     * Set a TTL (in seconds), so that the session expires after that time.
     *
     * @param int $ttl sets a ttl, -1 to disable it [means: cookie persists until browser close, or $config["maxlife"],
     *     whatever comes first]
     */
    public function setTtl(int $ttl) {
        $this->ttl = $ttl;
    }

    private function saveConfig() {
        $config = $this->request->getLocalVar("aerys.session.config");
        $config["ttl"] = $this->ttl;
        $this->request->setLocalVar("aerys.session.config", $config);
    }

    public function has($key) {
        if ($this->state === self::PENDING) {
            throw new LockException("Session is in lock pending state, wait until the Promise returned by Session::open() is resolved");
        }

        return array_key_exists($key, $this->data);
    }

    public function get($key) {
        if ($this->state === self::PENDING) {
            throw new LockException("Session is in lock pending state, wait until the Promise returned by Session::open() is resolved");
        }

        return $this->data[$key] ?? null;
    }

    public function set($key, $value) {
        if ($this->state !== self::LOCKED) {
            if ($this->state === self::PENDING) {
                throw new LockException("Session is not yet locked, wait until the Promise returned by Session::open() is resolved");
            } else {
                throw new LockException("Session is not locked, can't write");
            }
        }

        $this->data[$key] = $value;
    }

    public function unset($offset) {
        if ($this->state !== self::LOCKED) {
            if ($this->state === self::PENDING) {
                throw new LockException("Session is not yet locked, wait until the Promise returned by Session::open() is resolved");
            } else {
                throw new LockException("Session is not locked, can't write");
            }
        }

        unset($this->data[$offset]);
    }

    /**
     * Creates a lock and reads the current session data.
     *
     * @return Promise Resolving after success.
     */
    public function open(): Promise {
        if ($this->state !== self::UNLOCKED) {
            throw new LockException("Session already opened, can't open again");
        }

        if (!$this->id) {
            $this->state = self::LOCKED;

            return new Success($this);
        } else {
            $this->state = self::PENDING;

            return call(function () {
                try {
                    $data = yield $this->driver->open($this->id);

                    if (empty($data)) {
                        $this->setId(false);
                    }

                    $this->state = self::LOCKED;
                    $this->data = $data;

                    return $this;
                } catch (\Throwable $e) {
                    $this->state = self::UNLOCKED;

                    throw $e;
                }
            });
        }
    }

    /**
     * Saves and unlocks a session
     *
     * @return \Amp\Promise resolving after success
     */
    public function save(): Promise {
        if ($this->state !== self::LOCKED) {
            if ($this->state === self::PENDING) {
                throw new LockException("Session is not yet locked, wait until the Promise returned by Session::open() is resolved");
            } else {
                throw new LockException("Session is not locked, can't write");
            }
        }

        if (!$this->data) {
            return $this->destroy();
        }

        $this->state = self::PENDING;
        if (!$this->id) {
            $this->setId($this->generateId());
        }

        /* if we wait until "browser close", save the session for at most $config["maxlife"] (just to not have the sessions indefinitely...) */
        $deferred = new Deferred;
        $this->driver->save($this->id, $this->data, $this->ttl == -1 ? $this->maxlife : $this->ttl + 1)->onResolve(function ($e) use ($deferred) {
            if ($e) {
                $this->driver->read($this->id)->onResolve(function ($unlockE, $data) use ($deferred, $e) {
                    $this->state = self::UNLOCKED;
                    if ($unlockE) {
                        $this->data = [];
                        $deferred->fail($unlockE);
                    } else {
                        $this->data = $data;
                        $deferred->fail($e);
                    }
                });
            } else {
                $this->saveConfig();
                $this->state = self::UNLOCKED;
                $deferred->resolve($this);
            }
        });

        return $deferred->promise();
    }

    /**
     * Reloads the session contents without lock.
     *
     * Note: If the session needs to be altered, use `open()`.
     *
     * @return Promise Resolving after success.
     *
     * @see Session::open()
     */
    public function read(): Promise {
        if ($this->state) {
            throw new LockException("Session is locked, can't read in locked state; use the return value of the call to \\Aerys\\Session::open()");
        }

        return $this->id === null ? new Success($this) : call(function () {
            $data = yield $this->driver->read($this->id);

            if (empty($data)) {
                $this->setId(false);
            }

            $this->data = $data;

            return $this;
        });
    }

    /**
     * Unlocks the session, reloads data without saving.
     *
     * @return Promise Resolving after success.
     */
    public function unlock(): Promise {
        if (!$this->state) {
            throw new LockException("Session is not locked, can't unlock");
        }

        $this->data = [];

        if ($this->id) {
            $this->state = self::PENDING;

            return call(function () {
                try {
                    yield $this->driver->unlock($this->id);

                    $this->data = yield $this->driver->read($this->id);
                } catch (\Throwable $e) {
                    $this->data = [];

                    throw $e;
                } finally {
                    $this->state = self::UNLOCKED;
                }

                return $this;
            });
        } else {
            $this->state = self::UNLOCKED;

            return new Success($this);
        }
    }

    /**
     * Regenerates a session id.
     *
     * @return Promise Resolving after success.
     */
    public function regenerate(): Promise {
        if ($this->state !== self::LOCKED) {
            if ($this->state === self::PENDING) {
                throw new LockException("Session is not yet locked, wait until the Promise returned by Session::open() is resolved");
            } else {
                throw new LockException("Session is not locked, can't write");
            }
        }

        if ($this->id) {
            $new = $this->generateId();
            return call(function () use ($new) {
                yield $this->driver->regenerate($this->id, $new);
                $this->setId($new);

                return $this;
            });
        } else {
            return new Success($this);
        }
    }

    /**
     * Destroys the session.
     *
     * @return Promise Resolving after success.
     */
    public function destroy(): Promise {
        if ($this->state !== self::LOCKED) {
            if ($this->state === self::PENDING) {
                throw new LockException("Session is not yet locked, wait until the Promise returned by Session::open() is resolved");
            } else {
                throw new LockException("Session is not locked, can't write");
            }
        }

        $this->data = [];
        $this->state = self::UNLOCKED;

        if ($this->id) {
            $this->setId(false);

            return call(function () {
                yield $this->driver->save($this->id, [], $this->ttl == -1 ? $this->maxlife : $this->ttl + 1);

                return $this;
            });
        } else {
            return new Success($this);
        }
    }

    public function __destruct() {
        if ($this->state === self::LOCKED) {
            throw new LockException("Session must be unlocked (or saved) before being freed");
        }
    }

    public function __debugInfo() {
        return [
            "state" => ["UNLOCKED", "LOCKED", "PENDING"][$this->state],
            "id" => $this->id,
            "driver" => get_class($this->driver),
            "ttl" => $this->ttl,
            "maxlife" => $this->maxlife,
            "data" => $this->data,
        ];
    }
}
