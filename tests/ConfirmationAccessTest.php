<?php

use PHPUnit\Framework\TestCase;

/**
 * Ownership rule of the confirmation page (FP-16), tested without PrestaShop.
 */
class ConfirmationAccessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/classes/ConfirmationAccess.php';
    }

    public function testOwnerByCart()
    {
        $this->assertTrue(ConfirmationAccess::isCartOwnedBySession(57, 0, false, 57, 44));
    }

    public function testOwnerByAuthenticatedCustomerWhenSessionCartWasDropped()
    {
        $this->assertTrue(ConfirmationAccess::isCartOwnedBySession(60, 44, true, 57, 44));
    }

    public function testOtherCartAndOtherCustomerIsNotOwner()
    {
        $this->assertFalse(ConfirmationAccess::isCartOwnedBySession(60, 45, true, 57, 44));
    }

    public function testNoSessionIsNotOwner()
    {
        $this->assertFalse(ConfirmationAccess::isCartOwnedBySession(0, 0, false, 57, 44));
    }

    public function testSameCustomerIdButNotAuthenticatedIsNotOwner()
    {
        $this->assertFalse(ConfirmationAccess::isCartOwnedBySession(0, 44, false, 57, 44));
    }

    public function testCartWithoutCustomerIsNeverOwned()
    {
        $this->assertFalse(ConfirmationAccess::isCartOwnedBySession(0, 0, true, 57, 0));
        $this->assertFalse(ConfirmationAccess::isCartOwnedBySession(0, 0, true, 0, 0));
    }

    public function testNonExistentCartIsNotOwnedEvenIfSessionCartIsZero()
    {
        $this->assertFalse(ConfirmationAccess::isCartOwnedBySession(0, 0, false, 0, 0));
    }
}
