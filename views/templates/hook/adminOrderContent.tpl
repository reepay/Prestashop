{* 
 * NOTICE OF LICENSE
 * 
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 * 
 * You must not modify, adapt or create derivative works of this source code
 * 
 *  @author    LittleGiants
 *  @copyright 2019 LittleGiants
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *}
<style>
.product_action,
#add_voucher,
#add_product,
.current-edit.hidden-print {
  display: none;
}
</style>

<div class="card-1 no-print">

    <div class="card-header">
        <h3 class="card-header-title">
            Payment details
        </h3>
    </div>

<div class="payment-details">

    <div class="payment-details-figures" style="margin-left: 10px; float:left;">
        <strong>Status</strong>
        <p class="mb-0">{$invoice->state}</p>
        <strong>Order id</strong>
        <p class="mb-0">{$invoice->handle}</p>
        <strong>Invoice id</strong>
        <p class="mb-0"> {$invoice->id}</p>

        {if $invoice->transactions[0]->card_transaction->card_type}
            <strong>Card</strong>
            <p class="mb-0"> {$invoice->transactions[0]->card_transaction->card_type}</p>
            <strong>Masked card</strong>
            <p class="mb-0"> {$invoice->transactions[0]->card_transaction->masked_card}</p>
            <br/>
            {if $cardLogo}
                <img src="{$cardLogo}"/>
            {/if}
        {/if}
    </div>

    <div style="float:right; margin-right: 35%">
        <strong>amount</strong>
        <p class="mb-0"> {{$invoice->amount/100}} </p>
        <strong>Authorized amount</strong>
        <p class="mb-0"> {{$invoice->authorized_amount/100}} </p>
        <strong>Settled amount</strong>
        <p class="mb-0"> {{$invoice->settled_amount/100}} </p>
        <strong>Refunded amount</strong>
        <p class="mb-0"> {{$invoice->refunded_amount/100}} </p>
    </div>
    <div style="clear: both;"></div>
</div>

</div>

<div  class="card-1 no-print">
    <img src="{$logoSrc}"/>
    <form class="form-inline pull-right" action="{$formActionURL}" method="POST">
        <div class="form-group">
        <label>Refund: </label>
        <input type="hidden"  required name="orderNumber" value="{$orderNumber}">
        <input type="hidden" name="backURL" value="http://{$smarty.server.HTTP_HOST}{$smarty.server.REQUEST_URI}">
        {$refundAmountInput}
        </div>
        <button type="submit" class="btn btn-default {$refundButtonDisabled}">Refund order</button>
        <a target="_BLANK" href="{$dashboardURL}" class="btn btn-default">{l s='View order in Reepay' mod='reepay'}</a>
    </form>
    <table class="table">
        <thead>
            <tr>
            <th>{l s='Date' mod='reepay'}</th>
            <th>{l s='Type' mod='reepay'}</th>
            <th>{l s='Status' mod='reepay'}</th>
            <th style="text-align:right;">{l s='Amount' mod='reepay'}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$invoice->transactions item=transaction}
                <tr>
                    <td style="padding:.75rem;">
                        {$transaction->created|date_format:"%e %b %G %R:%S"}
                    </td>
                    <td style="padding:.75rem;">
                        {$transaction->type|ucfirst}
                    </td>
                    <td style="padding:.75rem;">
                        <span class="badge badge-pill badge-{$transaction->state}">{$transaction->state|ucfirst}</span>
                    </td>
                    <td style="text-align:right; padding:.75rem;">
                        {number_format($transaction->amount / 100, 2, '.', '') } {$invoice->currency}
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>
