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

<div class="panel">
	<div class="row">
		<div class="col-md-4 col-lg-3 text-center">
			<img class="m-3" src="{$module_dir|escape:'html':'UTF-8'}views/img/logo.svg">
			<small class="mt-negative">prestashop integration by littleGiants</small>
		</div>
		<div class="col-sm-8">
			<h4>Reepay Account Information</h4>
			<table class="table ">
                {if isset($account->name) && isset($account->email)}
                    <tbody>
                        <tr>
                            <td>Status</td>
                            <td>Authenticated</td>
                        </tr>
                        <tr>
                            <td>Name</td>
                            <td>{$account->name}</td>
                        </tr>
                        <tr>
                            <td>Email</td>
                            <td>{$account->email}</td>
                        </tr>
                    </tbody>
                {else}
                    <tbody>
                        <tr>
                            <td>Status</td>
                            <td>Not authenticated</td>
                        </tr>
                    </tbody>
                {/if}
            </table>
		</div>
	</div>  
</div>