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

{if (isset($status) == true) && ($status == 'ok')}
<h3>{l s='Your order on %s is complete.' sprintf=$shop_name mod='reepay'}</h3>
<p>
	<br />- {l s='Amount' mod='reepay'} : <span class="price"><strong>{$total|escape:'htmlall':'UTF-8'}</strong></span>
	<br />- {l s='Reference' mod='reepay'} : <span class="reference"><strong>{$reference|escape:'html':'UTF-8'}</strong></span>
	<br /><br />{l s='An email has been sent with this information.' mod='reepay'}
	<br /><br />{l s='If you have questions, comments or concerns, please contact our' mod='reepay'} <a href="{$link->getPageLink('contact', true)|escape:'html':'UTF-8'}">{l s='expert customer support team.' mod='reepay'}</a>
</p>
{else}
<h3>{l s='Your order on %s has not been accepted.' sprintf=$shop_name mod='reepay'}</h3>
<p>
	<br />- {l s='Reference' mod='reepay'} <span class="reference"> <strong>{$reference|escape:'html':'UTF-8'}</strong></span>
	<br /><br />{l s='Please, try to order again.' mod='reepay'}
	<br /><br />{l s='If you have questions, comments or concerns, please contact our' mod='reepay'} <a href="{$link->getPageLink('contact', true)|escape:'html':'UTF-8'}">{l s='expert customer support team.' mod='reepay'}</a>
</p>
{/if}
<hr />