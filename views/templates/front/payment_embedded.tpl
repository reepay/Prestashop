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

  <section>
    <input type="hidden" id="confirmURL" value="{$confirmURL}" />
    <input type="hidden" id="orderConfirmationURL" value="{$orderConfirmationURL}" />

    <div id='rp_container' style="width: 100%; height: 640px;"></div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/babel-polyfill/7.4.4/polyfill.min.js"></script>
    <script src="https://checkout.reepay.com/checkout.js"></script>
    <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
    <script>
      var rp = new Reepay.WindowCheckout('{$chargeSession->id}');
      var confirmURL = document.querySelector('#confirmURL').value
      var orderConfirmationURL = document.querySelector('#orderConfirmationURL').value

      rp.addEventHandler(Reepay.Event.Accept, function(data) {
        
        var formData = new FormData();
        formData.append('invoice', data.invoice)

        axios.post(confirmURL, formData).then(function (res){


        Swal.fire({
            title: '{$loadingText}',
            allowEscapeKey: false,
            allowOutsideClick: false,
            onOpen: function()
            {
              Swal.showLoading();
            }
        })

          if(res.data.status == "authorized"){
            window.location.replace(orderConfirmationURL);
          }
        }).catch(function(err) {
          console.log(err)
        })
      });

      rp.addEventHandler(Reepay.Event.Error, function(data) {
        console.log('Error', data);
      });

      rp.addEventHandler(Reepay.Event.Close, function(data) {
        console.log('Close', data);
      });

    </script>
  </section>
