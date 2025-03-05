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

<div class="row">
	<div class="col-xs-12 col-md-12">
		<p class="payment_module" id="reepay_payment_button">
			<a href="{$link->getModuleLink('reepay', 'payment', array(), true)|escape:'htmlall':'UTF-8'}" title="{$paymentOptionText}">
				<img src="{$module_dir|escape:'htmlall':'UTF-8'}/views/img/logo.png" alt="{$paymentOptionText}" height="32" />
				{$paymentOptionText}
			</a>
		</p>
	</div>
</div>
