<?php

namespace Inhere\WebSocket\Helpers\Frame;

use Inhere\WebSocket\Helpers\Exceptions\FrameException;
use Inhere\WebSocket\Protocols\Protocol;
use InvalidArgumentException;

/**
 * Class HybiFrame
 * @package Inhere\WebSocket\Helpers\Frame
 */
class HybiFrame extends Frame
{
    // First byte
    const BITFIELD_FINAL = 0x80;
    const BITFIELD_RSV1 = 0x40;
    const BITFIELD_RSV2 = 0x20;
    const BITFIELD_RSV3 = 0x10;
    const BITFIELD_TYPE = 0x0f;

    // Second byte
    const BITFIELD_MASKED = 0x80;
    const BITFIELD_INITIAL_LENGTH = 0x7f;

    // The inital byte offset before
    const BYTE_HEADER = 0;
    const BYTE_MASKED = 1;
    const BYTE_INITIAL_LENGTH = 1;

    /**
     * Whether the payload is masked
     * @var boolean
     */
    protected $masked = null;

    /**
     * Masking key
     * @var string
     */
    protected $mask = null;

    /**
     * Byte offsets
     * @var int
     */
    protected $offset_payload = null;
    protected $offset_mask = null;

    /**
     * Encode a frame
     *     ws-frame         = frame-fin           ; 1 bit in length
     *                        frame-rsv1          ; 1 bit in length
     *                        frame-rsv2          ; 1 bit in length
     *                        frame-rsv3          ; 1 bit in length
     *                        frame-opcode        ; 4 bits in length
     *                        frame-masked        ; 1 bit in length
     *                        frame-payload-length   ; either 7, 7+16,
     *                                               ; or 7+64 bits in
     *                                               ; length
     *                        [ frame-masking-key ]  ; 32 bits in length
     *                        frame-payload-data     ; n*8 bits in
     *                                               ; length, where
     *                                               ; n >= 0
     */
    public function encode($payload, $type = Protocol::TYPE_TEXT, $masked = false)
    {
        if (!is_int($type) || !in_array($type, Protocol::FRAME_TYPES)) {
            throw new InvalidArgumentException('Invalid frame type');
        }

        $this->type = $type;
        $this->masked = $masked;
        $this->payload = $payload;
        $this->length = strlen($this->payload);
        $this->offset_mask = null;
        $this->offset_payload = null;

        $this->buffer = "\x00\x00";

        $this->buffer[self::BYTE_HEADER] = chr(
            (self::BITFIELD_TYPE & $this->type)
            | (self::BITFIELD_FINAL & PHP_INT_MAX)
        );

        $masked_bit = (self::BITFIELD_MASKED & ($this->masked ? PHP_INT_MAX : 0));

        if ($this->length <= 125) {
            $this->buffer[self::BYTE_INITIAL_LENGTH] = chr(
                (self::BITFIELD_INITIAL_LENGTH & $this->length) | $masked_bit
            );
        } elseif ($this->length <= 65536) {
            $this->buffer[self::BYTE_INITIAL_LENGTH] = chr(
                (self::BITFIELD_INITIAL_LENGTH & 126) | $masked_bit
            );
            $this->buffer .= pack('n', $this->length);
        } else {
            $this->buffer[self::BYTE_INITIAL_LENGTH] = chr(
                (self::BITFIELD_INITIAL_LENGTH & 127) | $masked_bit
            );

            if (PHP_INT_MAX > 2147483647) {
                $this->buffer .= pack('NN', $this->length >> 32, $this->length);
                // $this->buffer .= pack('I', $this->length);
            } else {
                $this->buffer .= pack('NN', 0, $this->length);
            }
        }

        if ($this->masked) {
            $this->mask = $this->generateMask();
            $this->buffer .= $this->mask;
            $this->buffer .= $this->mask($this->payload);
        } else {
            $this->buffer .= $this->payload;
        }

        $this->offset_mask = $this->getMaskOffset();
        $this->offset_payload = $this->getPayloadOffset();

        return $this;
    }

    /**
     * Generates a suitable masking key
     * @return string
     */
    protected function generateMask()
    {
        if (extension_loaded('openssl')) {
            return openssl_random_pseudo_bytes(4);
        }

        // SHA1 is 128 bit (= 16 bytes)
        // So we pack it into 32 bits
        return pack('N', sha1(spl_object_hash($this) . mt_rand(0, PHP_INT_MAX) . uniqid('', true), true));
    }

    /**
     * Masks/Unmasks the frame
     * @param string $payload
     * @return string
     */
    protected function mask($payload)
    {
        $length = strlen($payload);
        $mask = $this->getMask();

        $unmasked = '';
        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $unmasked;
    }

    /**
     * Gets the mask
     * @throws FrameException
     * @return string
     */
    protected function getMask()
    {
        if (null === $this->mask) {
            if (!$this->isMasked()) {
                throw new FrameException('Cannot get mask: frame is not masked');
            }

            $this->mask = substr($this->buffer, $this->getMaskOffset(), $this->getMaskSize());
        }

        return $this->mask;
    }

    /**
     * Whether the frame is masked
     * @return bool
     * @throws FrameException
     */
    public function isMasked()
    {
        if (null === $this->mask) {
            if (!isset($this->buffer[1])) {
                throw new FrameException('Cannot tell if frame is masked: not enough frame data received');
            }

            $this->masked = (boolean)(ord($this->buffer[1]) & self::BITFIELD_MASKED);
        }

        return $this->masked;
    }

    /**
     * Gets the offset in the frame to the masking bytes
     * @return int
     */
    protected function getMaskOffset()
    {
        if (null === $this->offset_mask) {
            $offset = self::BYTE_INITIAL_LENGTH + 1;
            $offset += $this->getLengthSize();

            $this->offset_mask = $offset;
        }

        return $this->offset_mask;
    }

    /**
     * Returns the byte size of the length part of the frame
     * Not including the initial 7 bit part
     * @return int
     */
    protected function getLengthSize()
    {
        $initial = $this->getInitialLength();

        if ($initial < 126) {
            return 0;
        }

        if ($initial === 126) {
            return 2;
        }

        if ($initial === 127) {
            return 8;
        }
    }

    /**
     * Gets the inital length value, stored in the first length byte
     * This determines how the rest of the length value is parsed out of the
     * frame.
     * @return int
     * @throws FrameException
     */
    protected function getInitialLength()
    {
        if (!isset($this->buffer[self::BYTE_INITIAL_LENGTH])) {
            throw new FrameException('Cannot yet tell expected length');
        }

        // $a = (int)(ord($this->buffer[self::BYTE_INITIAL_LENGTH]) & self::BITFIELD_INITIAL_LENGTH);

        return (int)(ord($this->buffer[self::BYTE_INITIAL_LENGTH]) & self::BITFIELD_INITIAL_LENGTH);
    }

    /**
     * Returns the byte size of the mask part of the frame
     * @return int
     */
    protected function getMaskSize()
    {
        if ($this->isMasked()) {
            return 4;
        }

        return 0;
    }

    /**
     * Gets the offset of the payload in the frame
     * @return int
     */
    protected function getPayloadOffset()
    {
        if (null === $this->offset_payload) {
            $offset = $this->getMaskOffset();
            $offset += $this->getMaskSize();

            $this->offset_payload = $offset;
        }

        return $this->offset_payload;
    }

    /**
     * @param string $data
     */
    public function receiveData($data)
    {
        if ($this->getBufferLength() <= self::BYTE_INITIAL_LENGTH) {
            $this->length = null;
            $this->offset_payload = null;
        }

        parent::receiveData($data);
    }

    /**
     * @return bool
     * @throws FrameException
     */
    public function isFinal()
    {
        if (!isset($this->buffer[self::BYTE_HEADER])) {
            throw new FrameException('Cannot yet tell if frame is final');
        }

        return (boolean)(ord($this->buffer[self::BYTE_HEADER]) & self::BITFIELD_FINAL);
    }

    /**
     * @throws FrameException
     */
    public function getType()
    {
        if (!isset($this->buffer[self::BYTE_HEADER])) {
            throw new FrameException('Cannot yet tell type of frame');
        }

        $type = (int)(ord($this->buffer[self::BYTE_HEADER]) & self::BITFIELD_TYPE);

        if (!in_array($type, Protocol::FRAME_TYPES)) {
            throw new FrameException('Invalid payload type');
        }

        return $type;
    }

    protected function getExpectedBufferLength()
    {
        return $this->getLength() + $this->getPayloadOffset();
    }

    public function getLength()
    {
        if (!$this->length) {
            $initial = $this->getInitialLength();

            if ($initial < 126) {
                $this->length = $initial;
            } elseif ($initial >= 126) {
                // Extended payload length: 2 or 8 bytes
                $start = self::BYTE_INITIAL_LENGTH + 1;
                $end = self::BYTE_INITIAL_LENGTH + $this->getLengthSize();

                if ($end >= $this->getBufferLength()) {
                    throw new FrameException('Cannot get extended length: need more data');
                }

                $length = 0;
                for ($i = $start; $i <= $end; $i++) {
                    $length <<= 8;
                    $length += ord($this->buffer[$i]);
                }

                $this->length = $length;
            }
        }

        return $this->length;
    }

    protected function decodeFramePayloadFromBuffer()
    {
        $payload = substr($this->buffer, $this->getPayloadOffset());

        if ($this->isMasked()) {
            $payload = $this->unmask($payload);
        }

        $this->payload = $payload;
    }

    /**
     * Masks a payload
     * @param string $payload
     * @return string
     */
    protected function unmask($payload)
    {
        return $this->mask($payload);
    }
}
