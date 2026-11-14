<?php

use PHPUnit\Framework\TestCase;

if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', dirname(__DIR__) . '/../');
}

/**
 * The customer-binding rule of ReepayChargeVerifier (FP-19), tested without PrestaShop.
 */
class ReepayChargeVerifierCustomerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/classes/ReepayChargeVerifier.php';
    }

    public function testCustomerHandleMatchesWhatTheSessionSends()
    {
        $this->assertSame('c_37', ReepayChargeVerifier::customerHandle(37));
    }

    public function testAcceptsChargeOfSameCustomer()
    {
        $this->assertTrue(ReepayChargeVerifier::chargeBelongsToCustomer((object) array('customer' => 'c_37'), 37));
    }

    public function testRejectsChargeOfOtherCustomer()
    {
        $this->assertFalse(ReepayChargeVerifier::chargeBelongsToCustomer((object) array('customer' => 'c_38'), 37));
    }

    public function testRejectsChargeWithoutCustomer()
    {
        $this->assertFalse(ReepayChargeVerifier::chargeBelongsToCustomer((object) array('handle' => '47'), 37));
    }

    public function testRejectsNonScalarCustomerAndNonObject()
    {
        $this->assertFalse(ReepayChargeVerifier::chargeBelongsToCustomer((object) array('customer' => array('c_37')), 37));
        $this->assertFalse(ReepayChargeVerifier::chargeBelongsToCustomer(null, 37));
    }

    public function testRejectsCartWithoutCustomer()
    {
        $this->assertFalse(ReepayChargeVerifier::chargeBelongsToCustomer((object) array('customer' => 'c_0'), 0));
    }

    public function testDoesNotMatchByPrefix()
    {
        $this->assertFalse(ReepayChargeVerifier::chargeBelongsToCustomer((object) array('customer' => 'c_370'), 37));
    }
}
