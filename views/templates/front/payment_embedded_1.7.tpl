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
 
{extends "$layout"}

{block name="content"}
  <section>
    <input type="hidden" id="confirmURL" value="{$confirmURL}" />
    <input type="hidden" id="orderConfirmationURL" value="{$orderConfirmationURL}" />
    
    <div id='rp_container' style="width: 100%; height: 640px;"></div>
    <script src="{$frisbii_checkout_url}"></script>
    <script>
      const rp = new Reepay.ModalCheckout('{$chargeSession->id}');
      rp.addEventHandler(Reepay.Event.Accept, function(data) {
        let confirmationUrl = '{$confirmURL}?id=' + data.id + '&invoice=' + data.invoice + '&customer=' + data.customer;
        window.location.replace(prepareUrl(confirmationUrl));
      });

      rp.addEventHandler(Reepay.Event.Error, function(data) {
        console.log('Error', data);
      });

      rp.addEventHandler(Reepay.Event.Cancel, function(data) {
          let confirmationUrl = '{$confirmURL}';
          window.location.replace(prepareUrl(confirmationUrl));
      });

      function prepareUrl(url) {
          return url.replaceAll('&amp;', '&');
      }
    </script>
  </section>
{/block}
