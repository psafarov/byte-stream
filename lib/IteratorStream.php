<?php

namespace Amp\ByteStream;

use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\Iterator;
use Amp\Promise;
use Amp\Success;

/**
 * Creates a buffered message from an Iterator. The message can be consumed in chunks using the read() API or it may be
 * buffered and accessed in its entirety by waiting for the promise to resolve.
 *
 * Buffering Example:
 *
 * $stream = new IteratorStream($iterator); // $iterator is an instance of \Amp\Iterator emitting only strings.
 * $content = yield $stream;
 *
 * Streaming Example:
 *
 * $stream = new IteratorStream($iterator); // $iterator is an instance of \Amp\Iterator emitting only strings.
 *
 * while (($chunk = yield $stream->read()) !== null) {
 *     // Immediately use $chunk, reducing memory consumption since the entire message is never buffered.
 * }
 */
class IteratorStream implements InputStream, Promise {
    /** @var string */
    private $buffer = "";

    /** @var \Amp\Deferred|null */
    private $pendingRead;

    /** @var \Amp\Coroutine */
    private $coroutine;

    /** @var bool True if onResolve() has been called. */
    private $buffering = false;

    /** @var \Amp\Deferred|null */
    private $backpressure;

    /** @var bool True if close() is called or the iterator has completed. */
    private $closed = false;

    /**
     * @param \Amp\Iterator $iterator An iterator that only emits strings.
     */
    public function __construct(Iterator $iterator) {
        $this->coroutine = new Coroutine($this->iterate($iterator));
    }

    private function iterate(Iterator $iterator): \Generator {
        while (yield $iterator->advance()) {
            $buffer = $this->buffer .= $iterator->getCurrent();

            if ($buffer === "") {
                continue; // Do not succeed reads with empty string.
            } elseif ($this->pendingRead) {
                $deferred = $this->pendingRead;
                $this->pendingRead = null;
                $this->buffer = "";
                $deferred->resolve($buffer);
            } elseif (!$this->buffering) {
                $this->backpressure = new Deferred;
                yield $this->backpressure->promise();
            }

            $buffer = ""; // Destroy last emitted chunk to free memory.
        }

        $this->closed = true;

        if ($this->pendingRead) {
            $deferred = $this->pendingRead;
            $this->pendingRead = null;
            $deferred->resolve($this->buffer !== "" ? $this->buffer : null);
            $this->buffer = "";
        }

        return $this->buffer;
    }

    public function read(): Promise {
        if ($this->pendingRead) {
            return new Failure(new PendingReadException);
        }

        if ($this->closed) {
            return new Success;
        }

        if ($this->buffer !== "") {
            $buffer = $this->buffer;
            $this->buffer = "";

            if ($this->backpressure) {
                $backpressure = $this->backpressure;
                $this->backpressure = null;
                $backpressure->resolve();
            }

            return new Success($buffer);
        }

        $this->pendingRead = new Deferred;
        return $this->pendingRead->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function onResolve(callable $onResolved) {
        $this->buffering = true;

        if ($this->backpressure) {
            $backpressure = $this->backpressure;
            $this->backpressure = null;
            $backpressure->resolve();
        }

        $this->coroutine->onResolve($onResolved);
    }

    public function close() {
        $this->buffering = true;
        $this->closed = true;

        if ($this->pendingRead) {
            $deferred = $this->pendingRead;
            $this->pendingRead = null;
            $deferred->resolve($this->buffer === "" ? $this->buffer : null);
            $this->buffer = "";
        }
    }
}
