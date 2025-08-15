(function($){
  'use strict';

  // —————————————————————————————
  // Part 1: Product‑page pricing
  // —————————————————————————————
  $(function(){
    var $wrapper = $('#sep-wrapper'),
        $total   = $('#sep-total'),
        $tos     = $('#sep-tos');

 /**
 * Recalculate the cart total when options or date ranges change.
 * - Preserves existing flat‑fee options logic.
 * - Replaces flat fees for ad_home & ad_side with pro‑rated date‑range logic.
 */
function recalc(){
  var sum = 0;

  // 1) Existing sponsorship checkboxes (all except ad_home & ad_side)
  $wrapper.find('input[name="sep_opts[]"]:checked').each(function(){
    var key = this.value;
    if ( key === 'ad_home' || key === 'ad_side' || key === 'link_pinned' ) {
      return; // skip ad slots here
    }
    sum += parseInt( $(this).data('price')||0, 10 );
  });

  // 2) Homepage Banner Ad pro‑rated pricing
  var $homeRange = $('input.sep-ad-range[data-slot="ad_home"]'),
      homeVal    = $homeRange.val();
  if ( homeVal ) {
    // Parse selected range: "YYYY-MM-DD — YYYY-MM-DD"
    var parts = homeVal.split('—').map(s => s.trim()),
        d1    = new Date(parts[0]),
        d2    = new Date(parts[1]),
        days  = ((d2 - d1) / 86400000) + 1;                 // inclusive day count
    var costHome = 300 + Math.max(0, days - 7) * 42.85;    // base + extra days
    sum += parseFloat( costHome.toFixed(2) );             // round to 2 decimals
  }

  // 3) Side Banner Ad pro‑rated pricing
  var $sideRange = $('input.sep-ad-range[data-slot="ad_side"]'),
      sideVal    = $sideRange.val();
  if ( sideVal ) {
    var parts2 = sideVal.split('—').map(s => s.trim()),
        s1     = new Date(parts2[0]),
        s2     = new Date(parts2[1]),
        sDays  = ((s2 - s1) / 86400000) + 1;
    var costSide = 150 + Math.max(0, sDays - 7) * 21.42;
    sum += parseFloat( costSide.toFixed(2) );
  }
	
	// 4) 30‑Day Pinned Article prorated pricing
$('input.sep-ad-range[data-slot^="link_pinned_"]').each(function(){
  var $inp  = $(this),
      val   = $inp.val(),
      slot  = $inp.data('slot');

  if ( val ) {
    // parse dates
    var parts = val.split('—').map(s=>s.trim()),
        d1    = new Date(parts[0]),
        d2    = new Date(parts[1]),
        days  = ((d2 - d1)/86400000) + 1,

        // constants
        base    = 500,
        perDay  = parseFloat((base/30).toFixed(2)),
        extra   = Math.max(0, days - 30),
        cost    = base + extra * perDay;

    sum += parseFloat(cost.toFixed(2));
  }
});

  // 5) Update UI and Buy Now button state
  $total.text(
    'Total: $' +
    sum.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    })
  );
  $('button.single_add_to_cart_button')
    .prop('disabled', sum < 0.01 || ! $tos.is(':checked') );
}

	  
    $wrapper.on('change', 'input[name="sep_opts[]"],#sep-tos', recalc);
	// Also recalc whenever a date‑range input changes
    $wrapper.on('change', 'input.sep-ad-range', recalc);  
    recalc();
  });

  // —————————————————————————————
  // Part 2: Thank‑You page filtering
  // —————————————————————————————
  $(function(){
    if ( ! Array.isArray( window.sepPurchased ) || ! window.sepPurchased.length ) {
      return;
    }

    // Hide all fields first
    $('.elementor-form .elementor-field-group').hide();
	 
	  // Always show the Submit button group
    $('.elementor-form .elementor-field-type-submit').show();  

    // Then only show those purchased
    $('.elementor-form .elementor-field-group').each(function(){
      var $grp     = $(this),
          $field   = $grp.find('input,select,textarea'),
          nameAttr = $field.attr('name') || '',
          match    = nameAttr.match(/form_fields\[(\w+)\]/),
          key      = match ? match[1] : '';

      if ( key && window.sepPurchased.indexOf(key) !== -1 ) {
        $grp.show();
      } else {
        $field.prop('required', false);
      }
    });
	  
	  if ( typeof window.sepOrderId === 'undefined' ) {
    return;
  }

  $('#form-field-name').val(     window.sepCustomerName );
  $('#form-field-email').val(    window.sepCustomerEmail );
  $('#form-field-order').val(    window.sepOrderId );
	  
  });
	
	 // ——————————————
  // Range‑Picker Logic
  // ——————————————
  $(function(){
   
	  // 1) When any checkbox toggles:
$('#sep-wrapper').on('change', 'input[name="sep_opts[]"]', function(){
  var key     = this.value,
      checked = this.checked;

  // ——— Banner slots: ad_home & ad_side ———
  if ( key === 'ad_home' || key === 'ad_side' ) {
    // find the single range input and its wrapper
    var $input = $('.sep-ad-range[data-slot="'+ key +'"]'),
        $wrap  = $input.closest('.sep-ad-range-wrapper');

    if ( checked ) {
      $wrap.slideDown(200);
      initRangePicker($input);
    } else {
      $wrap.slideUp(200);
      $input.val('').trigger('change');
    }

    return;
  }

  // ——— 30‑Day Pinned Article: show/hide all 4 slots ———
  if ( key === 'link_pinned' ) {
    // select all four range inputs by slot‑prefix
    var $all    = $('.sep-ad-range[data-slot^="link_pinned_"]'),
        $wraps  = $all.closest('.sep-ad-range-wrapper');

    if ( checked ) {
      $wraps.slideDown(200);
      $all.each(function(){
        initRangePicker( $(this) );
      });
    } else {
      $wraps.slideUp(200);
      $all.each(function(){
        $(this).val('').trigger('change');
      });
    }

    return;
  }

  // ——— All other options: no date‑range behavior ———
});


    // 2) Initialize Flatpickr on the input (only once)
   function initRangePicker($input){
  if ( $input.data('fp-initialized') ) return;
  $input.data('fp-initialized', true);

  var slot     = $input.data('slot'),
      ajax_url = window.SEP_DATA.ajax_url,

      // Choose parameters by slot
      isPinned = slot.indexOf('link_pinned') === 0,   // true for link_pinned_1…4
    // determine parameters by slot type:
    minDays  = isPinned ? 30 : 7,
    base     = slot === 'ad_side'      ? 150
             : isPinned                ? 500
             : 300,
    perDay   = parseFloat((base / minDays).toFixed(2));

  // Fetch disabled ranges...
  $.post( ajax_url,
    { action:'sep_get_reserved_ranges', slot:slot },
    function(resp){
      var disabled = (resp.success && Array.isArray(resp.data))
                   ? resp.data.map(r=>({from:r.from,to:r.to}))
                   : [];

      flatpickr( $input[0], {
        mode: 'range',
        dateFormat: 'Y-m-d',
        disable: disabled,
        minDate: 'today',
        onClose: function(selDates, dateStr, instance) {
          if ( selDates.length === 2 ) {
            // inclusive days
            var days = ((selDates[1] - selDates[0]) / 86400000) + 1;
            if ( days < minDays ) {
              alert('Please select at least ' + minDays + ' days.');
              $input.val('');
              $input.trigger('change');
            } else {
              var a = instance.formatDate(selDates[0],'Y-m-d'),
                  b = instance.formatDate(selDates[1],'Y-m-d');
              $input.val(a + ' — ' + b).trigger('change');
            }
          }
        }
      });
    },
    'json'
  );
}
  });

})(jQuery);


