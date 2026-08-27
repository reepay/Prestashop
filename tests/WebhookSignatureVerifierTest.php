<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for signature/payload rules used by the Reepay webhook endpoint.
 * No PrestaShop bootstrap required.
 */
class WebhookSignatureVerifierTest extends TestCase
{
    private function validPayload()
    {
        return [
            'timestamp' => '1700000000',
            'id' => 'evt_123',
            'signature' => 'irrelevant-for-this-test',
            'event_type' => 'invoice_authorized',
            'invoice' => '42',
        ];
    }

    public function testValidatePayloadAcceptsCompletePayload()
    {
        $this->assertTrue(WebhookSignatureVerifier::validatePayload($this->validPayload()));
    }

    /**
     * @dataProvider requiredFieldProvider
     */
    public function testValidatePayloadRejectsMissingRequiredField($field)
    {
        $payload = $this->validPayload();
        unset($payload[$field]);

        $this->assertFalse(WebhookSignatureVerifier::validatePayload($payload));
    }

    /**
     * @dataProvider requiredFieldProvider
     */
    public function testValidatePayloadRejectsEmptyRequiredField($field)
    {
        $payload = $this->validPayload();
        $payload[$field] = '';

        $this->assertFalse(WebhookSignatureVerifier::validatePayload($payload));
    }

    public function requiredFieldProvider()
    {
        return [
            'timestamp' => ['timestamp'],
            'id' => ['id'],
            'signature' => ['signature'],
            'event_type' => ['event_type'],
            'invoice' => ['invoice'],
        ];
    }

    public function testValidatePayloadRejectsNonArray()
    {
        $this->assertFalse(WebhookSignatureVerifier::validatePayload(null));
    }

    public function testComputeSignatureReturnsLowercaseHex()
    {
        $signature = WebhookSignatureVerifier::computeSignature('1700000000', 'evt_123', 'my-secret');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    public function testComputeSignatureMatchesKnownHmacVector()
    {
        // Reference vector computed independently with hash_hmac('sha256', ...).
        $expected = hash_hmac('sha256', '1700000000evt_123', 'my-secret');

        $signature = WebhookSignatureVerifier::computeSignature('1700000000', 'evt_123', 'my-secret');

        $this->assertSame($expected, $signature);
    }

    public function testIsValidSignatureAcceptsCorrectSignature()
    {
        $secret = 'my-secret';
        $signature = WebhookSignatureVerifier::computeSignature('1700000000', 'evt_123', $secret);

        $this->assertTrue(WebhookSignatureVerifier::isValidSignature('1700000000', 'evt_123', $signature, $secret));
    }

    public function testIsValidSignatureRejectsTamperedSignature()
    {
        $secret = 'my-secret';
        $signature = WebhookSignatureVerifier::computeSignature('1700000000', 'evt_123', $secret);
        $tampered = substr($signature, 0, -1) . (($signature[strlen($signature) - 1] === 'a') ? 'b' : 'a');

        $this->assertFalse(WebhookSignatureVerifier::isValidSignature('1700000000', 'evt_123', $tampered, $secret));
    }

    public function testIsValidSignatureRejectsWrongSecret()
    {
        $signature = WebhookSignatureVerifier::computeSignature('1700000000', 'evt_123', 'correct-secret');

        $this->assertFalse(WebhookSignatureVerifier::isValidSignature('1700000000', 'evt_123', $signature, 'wrong-secret'));
    }

    public function testIsValidSignatureRejectsEmptySignature()
    {
        $this->assertFalse(WebhookSignatureVerifier::isValidSignature('1700000000', 'evt_123', '', 'my-secret'));
    }
}
