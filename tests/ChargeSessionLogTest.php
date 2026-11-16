<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/ChargeSessionLog.php';

/**
 * Tests the debug log line of a charge session: it must show the payment_methods sent to
 * Frisbii and the outcome, and must not leak customer data.
 */
class ChargeSessionLogTest extends TestCase
{
    private function payload(array $extra = array())
    {
        return array(
            'order' => array(
                'handle' => 42,
                'customer' => array('email' => 'jane@example.com', 'first_name' => 'Jane'),
                'billing_address' => array('address' => '1 Secret Street'),
            ),
        ) + $extra;
    }

    public function testLogsSelectedMethodAndSessionId()
    {
        $msg = ChargeSessionLog::message($this->payload(array('payment_methods' => array('klarna'))), (object) array('id' => 'cs_abc123'), 'window');

        $this->assertSame('Frisbii charge session, order_handle=42, payment_methods=klarna, checkout_type=window, result=ok, session_id=cs_abc123', $msg);
    }

    public function testLogsNoneForGenericOption()
    {
        $msg = ChargeSessionLog::message($this->payload(), (object) array('id' => 'cs_1'), 'embedded');
        $this->assertStringContainsString('payment_methods=none', $msg);
    }

    public function testLogsMultipleMethods()
    {
        $msg = ChargeSessionLog::message($this->payload(array('payment_methods' => array('card', 'paypal'))), (object) array('id' => 'x'), 'window');
        $this->assertStringContainsString('payment_methods=card,paypal', $msg);
    }

    public function testLogsApiError()
    {
        $msg = ChargeSessionLog::message($this->payload(), (object) array('error' => 'Invalid payment method', 'code' => 105), 'window');
        $this->assertStringContainsString('result=error, error=Invalid payment method, code=105', $msg);
    }

    public function testLogsMissingResponse()
    {
        $this->assertStringContainsString('result=no valid response', ChargeSessionLog::message($this->payload(), null, 'window'));
    }

    public function testDoesNotLeakCustomerData()
    {
        $msg = ChargeSessionLog::message($this->payload(), (object) array('id' => 'cs_1'), 'window');
        $this->assertStringNotContainsString('jane', strtolower($msg));
        $this->assertStringNotContainsString('Secret', $msg);
    }

    public function testStripsUnexpectedCharactersFromValues()
    {
        $msg = ChargeSessionLog::message($this->payload(), (object) array('error' => "bad\n<script>x</script>"), 'window');
        $this->assertStringNotContainsString("\n", $msg);
        $this->assertStringNotContainsString('<', $msg);
    }
}
