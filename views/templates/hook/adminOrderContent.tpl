{* 
 * NOTICE OF LICENSE
 * 
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 * 
 * You must not modify, adapt or create derivative works of this source code
 * 
 *  @author    Frisbii
 *  @copyright 2026 Frisbii
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *}

{if $refundNotice}
    <div class="alert {if $refundNotice.success}alert-success{else}alert-danger{/if}" role="alert">
        {$refundNotice.message|escape:'html':'UTF-8'}
    </div>
{/if}

{if !$invoiceReadable}
    <div class="alert alert-warning" role="alert">
        {l s='The invoice could not be read at Frisbii. The payment details are not shown.' mod='reepay'}
    </div>
{/if}

{if $invoiceReadable}
<div class="card-1 no-print">

    <div class="card-header">
        <h3 class="card-header-title">
            Payment details
        </h3>
    </div>

<div class="payment-details">

    <div class="payment-details-figures" style="margin-left: 10px; float:left;">
        <strong>Status</strong>
        <p class="mb-0">{$invoice->state|escape:'html':'UTF-8'}</p>
        <strong>Order id</strong>
        <p class="mb-0">{$invoice->handle|escape:'html':'UTF-8'}</p>
        <strong>Invoice id</strong>
        <p class="mb-0"> {$invoice->id|escape:'html':'UTF-8'}</p>

        {if $cardType}
            <strong>Card</strong>
            <p class="mb-0"> {$cardType|escape:'html':'UTF-8'}</p>
            <strong>Masked card</strong>
            <p class="mb-0"> {$invoice->transactions[0]->card_transaction->masked_card|escape:'html':'UTF-8'}</p>
            <br/>
            {if $cardLogo}
                <img src="{$cardLogo|escape:'html':'UTF-8'}"/>
            {/if}
        {/if}
    </div>

    <div style="float:right; margin-right: 35%">
        <strong>amount</strong>
        <p class="mb-0"> {number_format((float)$invoice->amount / 100, 2, '.', '')} </p>
        <strong>Authorized amount</strong>
        <p class="mb-0"> {number_format((float)$invoice->authorized_amount / 100, 2, '.', '')} </p>
        <strong>Settled amount</strong>
        <p class="mb-0"> {number_format((float)$invoice->settled_amount / 100, 2, '.', '')} </p>
        <strong>Refunded amount</strong>
        <p class="mb-0"> {number_format((float)$invoice->refunded_amount / 100, 2, '.', '')} </p>
    </div>
    <div style="clear: both;"></div>
</div>

</div>
{/if}

<div  class="card-1 no-print">
    <img src="{$logoSrc|escape:'html':'UTF-8'}" width="81px" height="24px" alt="Frisbii logo"/>
    <form class="form-inline pull-right" action="{$formActionURL|escape:'html':'UTF-8'}" method="POST">
        <div class="form-group">
        <label>Refund: </label>
        <input type="hidden" required name="id_order" value="{$orderId|intval}">
        {$refundAmountInput}
        </div>
        <button type="submit" class="btn btn-default {$refundButtonDisabled}">Refund order</button>
        <a target="_BLANK" href="{$dashboardURL|escape:'html':'UTF-8'}" class="btn btn-default">{l s='View order in Frisbii Payments' mod='reepay'}</a>
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
            {if $invoiceReadable}
            {foreach from=$invoice->transactions item=transaction}
                <tr>
                    <td style="padding:.75rem;">
                        {$transaction->created|date_format:"%e %b %G %R:%S"}
                    </td>
                    <td style="padding:.75rem;">
                        {$transaction->type|ucfirst|escape:'html':'UTF-8'}
                    </td>
                    <td style="padding:.75rem;">
                        <span class="badge badge-pill badge-{$transaction->state|escape:'html':'UTF-8'}">{$transaction->state|ucfirst|escape:'html':'UTF-8'}</span>
                    </td>
                    <td style="text-align:right; padding:.75rem;">
                        {number_format((float)$transaction->amount / 100, 2, '.', '')} {$invoice->currency|escape:'html':'UTF-8'}
                    </td>
                </tr>
            {/foreach}
            {/if}
        </tbody>
    </table>
</div>
