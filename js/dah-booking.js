// File: js/dah-booking.js
(function($){
  'use strict';

  $(function(){

    if ( typeof DAHBooking === 'undefined' ) return;

    const {
      restBase, ajaxUrl, propertyId, propertyName,
      minNights, maxNights, currency, basePrice,
      depositPercent, depositDays, propertyThumbnail,
      arrowImgUrl, prepaymentUrl, paymentUrl
    } = DAHBooking;

    // Only run calendar code when #dah-calendar is present
    const $cal = $('#dah-calendar');
    if ( $cal.length ) {
      // cache the initial SVG
      const initialBtnSvg = $('.booking-btn svg').length
        ? $('.booking-btn svg')[0].outerHTML
        : '';

      let startDate, endDate;

      /**
       * Fetch availability from Guesty and disable any non-available days
       * @param {string} from YYYY-MM-DD
       * @param {string} to   YYYY-MM-DD
       * @param {FlatpickrInstance} fpInstance
       */
      function fetchAvailability(from, to, fpInstance){
  console.group(`fetchAvailability for ${from} → ${to}`);
  console.log('Requesting:', `${restBase}/calendar?propertyId=${propertyId}&from=${from}&to=${to}`);

  return $.getJSON(`${restBase}/calendar`, { propertyId, from, to })
    .done(daysArr => {
      console.log('Raw API response daysArr:', daysArr);

      const disabled = daysArr.filter(d => {
        // 1) status isn’t “available”
        if ( d.status !== 'available' ) return true;

        // 2) any explicit reservation block
        if ( Array.isArray(d.blockRefs) && d.blockRefs.length ) return true;

        // 3) any “blocks” flags set to true
        if ( Object.values(d.blocks||{}).some(v => v) ) return true;

        return false;
      }).map(d => d.date);

      console.log('Computed disabled dates:', disabled);

      fpInstance.set('disable', disabled);
      console.log('Flatpickr disable set — now calling redraw()');
      fpInstance.redraw();
    })
    .fail((jqXHR, textStatus, errorThrown) => {
      console.error('API error:', textStatus, errorThrown);
    })
    .always(() => {
      console.groupEnd();
    });
}

      // init Flatpickr, capturing the instance
      const fp = flatpickr('#dah-calendar', {
        inline:    true,
        mode:      'range',
        minDate:   'today',
        locale: { firstDayOfWeek: 1 },
        disable:   [],       // start with none disabled

        // on first render, disable non-available days in current month
        onReady(selectedDates, dateStr, instance){
          console.log('Flatpickr onReady: currentMonth=', instance.currentMonth, 'currentYear=', instance.currentYear);
const y = instance.currentYear;
const m = instance.currentMonth;     // zero-based: 0=Jan, 4=May, 5=June, etc.
const from = new Date(y, m, 1)        // first day of that month
                .toISOString().slice(0,10);
const to   = new Date(y, m+1, 0)      // zero gives you “day 0” of next month = last day of this month
                .toISOString().slice(0,10);
console.log(`fetching ${from} → ${to}`);
fetchAvailability(from, to, instance)
  .then(() => instance.redraw());
        },

        // when user navigates months, re-fetch for that month
        onMonthChange(selectedDates, dateStr, instance){
          console.log('Flatpickr onMonthChange: newMonth=', instance.currentMonth, 'newYear=', instance.currentYear);
const y = instance.currentYear;
const m = instance.currentMonth;     // zero-based: 0=Jan, 4=May, 5=June, etc.
const from = new Date(y, m, 1)        // first day of that month
                .toISOString().slice(0,10);
const to   = new Date(y, m+1, 0)      // zero gives you “day 0” of next month = last day of this month
                .toISOString().slice(0,10);
console.log(`fetching ${from} → ${to}`);
fetchAvailability(from, to, instance)
  .then(() => instance.redraw());
        },

        // when a range is picked, run your existing validation + pricing
        onChange(sel) {
          if ( sel.length < 2 ) return resetState();
          startDate = sel[0].toISOString().slice(0,10);
          endDate   = sel[1].toISOString().slice(0,10);

          fetchDepositPercent().then(() => {
            processSelection();
          });
          // processSelection();
        }
      });

      function resetState(){
        $('.Calendar_bookMessage__5Hh2x').text(`Book ${propertyName}`);
        $('.Calendar_dateLabel__v0z7B').html(
          `Arrival <img src="${arrowImgUrl}" class="Calendar_arrow__f19cY"> Departure`
        );
        $('.Calendar_price__GEKBc').text(`${currency}${basePrice}`);
        $('.Calendar_normalText__swkEq').text('(Per night. Pick dates for exact price)');
        $('.booking-btn')
          .addClass('disabled')
          .html(`<span>Pick some dates</span>${initialBtnSvg}`);
      }

      // function processSelection(){
      //   const nights = (new Date(endDate) - new Date(startDate)) / (1000*60*60*24);
      //   if ( nights < minNights ) {
      //     alert(`Minimum stay is ${minNights} night(s).`);
      //     return resetState();
      //   }
      //   const days = (new Date(endDate) - new Date(startDate)) / (1000*60*60*24);
      //   if ( days < minNights ) {
      //     return alert(`Minimum stay is ${minNights} night(s).`);
      //   }
      //   if ( maxNights && days > maxNights ) {
      //     return alert(`Maximum stay is ${maxNights} night(s).`);
      //   }

      //   // Guesty uses exclusive “to”
      //   const toDate = new Date(endDate);
      //   toDate.setDate(toDate.getDate()+1);
      //   const toParam = toDate.toISOString().slice(0,10);

      //   $.getJSON(`${restBase}/calendar`, { propertyId, from:startDate, to:toParam })
      //     .done(daysArr => {
      //       let total = 0, ok = true;
      //       daysArr.forEach(d => {
      //         if ( d.status !== 'available' ) ok = false;
      //         total += parseFloat(d.price||0);
      //       });
      //       if ( ! ok ) {
      //         return $('.Calendar_price__GEKBc').text('Some dates unavailable.');
      //       }

      //       $('.Calendar_bookMessage__5Hh2x').text(`Book ${propertyName}`);
      //       $('.Calendar_dateLabel__v0z7B').text(formatDate(startDate))
      //         .append(` <img src="${arrowImgUrl}" class="Calendar_arrow__f19cY"> `)
      //         .append(formatDate(endDate));
      //       $('.Calendar_price__GEKBc').text(`${currency}${total.toFixed(2)}`);
      //       $('.Calendar_normalText__swkEq').text('(Price includes utilities)');

      //       // swap in your “Book now” button
      //       $('.booking-btn').replaceWith(`
      //         <button type="submit" class="cta-main green booking-btn">
      //           <span>Book now</span>${initialBtnSvg}
      //         </button>
      //       `);

      //       const deposit = total * (depositPercent / 100);
      //       $('.deposit_percent').text(depositPercent);
      //       $('.total_deposit').text(`${currency}${deposit.toFixed(2)}`);
      //     })

      //     .fail(()=> alert('Error fetching pricing.'));
      // }

      let dynamicDepositPercent = depositPercent ?? null;
      let dynamicDepositDays    = depositDays ?? null;

      // Optional: fallback if API fails
      const fallbackDepositPercent = 0;
      const fallbackDepositDays    = 0;

      /**
       * Fetch the deposit policy (if not already available)
       * Returns a Promise
       */
      function fetchDepositPercent() {
        return $.getJSON(`${restBase}/paymentpolicy`, { propertyId })
          .then(data => {
            try {
              const policy = data.autoPayments && data.autoPayments.policy;
              const dp = parseFloat(policy?.[0]?.amount);
              if (!isNaN(dp)) {
                dynamicDepositPercent = dp;
                console.log(`Fetched dynamic depositPercent: ${dp}%`);
              } else {
                console.warn('Deposit amount missing in response. Fallback used.');
                dynamicDepositPercent = fallbackDepositPercent;
              }

              const dd = parseInt(policy?.[1]?.scheduleTo?.timeRelation?.amount);
              if (!isNaN(dd)) {
                dynamicDepositDays = dd;
              } else {
                dynamicDepositDays = fallbackDepositDays;
              }
            } catch (err) {
              console.error('Error parsing deposit policy:', err);
              dynamicDepositPercent = fallbackDepositPercent;
              dynamicDepositDays    = fallbackDepositDays;
            }
          })
          .catch(err => {
            console.error('Failed to fetch deposit policy:', err);
            dynamicDepositPercent = fallbackDepositPercent;
            dynamicDepositDays    = fallbackDepositDays;
          });
      }


      function processSelection(){
        const nights = (new Date(endDate) - new Date(startDate)) / (1000*60*60*24);
        if ( nights < minNights ) {
          alert(`Minimum stay is ${minNights} night(s).`);
          return resetState();
        }
        if ( maxNights && nights > maxNights ) {
          alert(`Maximum stay is ${maxNights} night(s).`);
          return resetState();
        }

        // Guesty uses exclusive “to”
        const toDate = new Date(endDate);
        toDate.setDate(toDate.getDate() + 1);
        const toParam = toDate.toISOString().slice(0, 10);

        $.getJSON(`${restBase}/calendar`, { propertyId, from: startDate, to: toParam })
          .done(daysArr => {
            let total = 0;
            let cleaningFeeOnce = 0;
            let ok = true;

            console.group('React-style Price Breakdown');
            daysArr.forEach((d, index) => {
              if (d.status !== 'available') ok = false;

              const nightly = parseFloat(d.adjustedPrice ?? d.price ?? 0);
              const guestFee = parseFloat(d.guestFee ?? 0);
              const cleaningFee = parseFloat(d.cleaningFee ?? 0);

              if (index === 0) {
                cleaningFeeOnce = cleaningFee;
              }

              const subtotal = nightly + guestFee;
              console.log(`${d.date}: nightly=${nightly}, guestFee=${guestFee}, cleaningFee${index === 0 ? `=${cleaningFee}` : `=0`}, total=${subtotal}`);
              total += subtotal;
            });

            total += cleaningFeeOnce;
            console.log(`Final total (with one-time cleaning fee): ${currency}${total.toFixed(0)}`);
            const deposit = total * (dynamicDepositPercent / 100);
            $('.deposit_percent').text(dynamicDepositPercent);
            console.log(`Deposit (${dynamicDepositPercent}%): ${currency}${deposit.toFixed(0)}`);
            console.groupEnd();

            if (!ok) {
              return $('.Calendar_price__GEKBc').text('Some dates unavailable.');
            }

            $('.Calendar_bookMessage__5Hh2x').text(`Book ${propertyName}`);
            $('.Calendar_dateLabel__v0z7B').html(
              `${formatDate(startDate)} <img src="${arrowImgUrl}" class="Calendar_arrow__f19cY"> ${formatDate(endDate)}`
            );
            $('.Calendar_price__GEKBc').text(`${currency}${total.toFixed(0)}`);
            
            $('.Calendar_price__Depos').text(`${currency}${deposit.toFixed(0)}`);

            $('.Calendar_normalText__swkEq').text('(Price includes utilities)');

            $('.booking-btn').replaceWith(`
              <button type="submit" class="cta-main green booking-btn">
                <span>Book now</span>${initialBtnSvg}
              </button>
            `);

            $('.deposit_percent').text(dynamicDepositPercent);
            $('.total_deposit').text(`${currency}${deposit.toFixed(0)}`);
          })
          .fail(() => alert('Error fetching pricing.'));
      }

















      

      function formatDate(iso){
        return new Date(iso).toLocaleDateString('en-US',{
          month: 'short', day: 'numeric', year: 'numeric'
        });
      }

      // Book → Prepayment
      $(document).on('submit','form[data-hs-cf-bound]', function(e){
        e.preventDefault();
        const nights = (new Date(endDate) - new Date(startDate)) / (1000*60*60*24);
        const order = {
          propertyId,
          propertyName,
          price: parseFloat($('.Calendar_price__GEKBc').text().replace(/\D/g,'')),
          arrivalDate:  startDate,
          departureDate: endDate,
          depositPercent: dynamicDepositPercent,
          nights,
          depositDays: dynamicDepositDays,
          propertyThumbnail
        };
        $.post(ajaxUrl,{
          action:'dah_save_order',
          order: JSON.stringify(order)
        })
        .always(()=>{
          document.cookie =
            'order=' + encodeURIComponent(JSON.stringify(order)) +
            '; path=/; max-age=' + (60*60);
          const qs = '?order='              + encodeURIComponent(JSON.stringify(order)) +
                     '&propertyThumbnail=' + encodeURIComponent(propertyThumbnail) +
                     '&depositPercent='    + dynamicDepositPercent +
                     '&depositDays='       + dynamicDepositDays;
          window.location.href = prepaymentUrl + qs;
        });
      });

      // init button state
      resetState();
    }

    // Only run prepayment form code when that form is present
    if ( $('#dah-purchase-form').length ) {
      $(document).on('submit','#dah-purchase-form', function(e){
        e.preventDefault();
        const formData = $(this).serializeArray().reduce((m,f)=>(m[f.name]=f.value,m),{});
        const raw = document.cookie
          .split('; ')
          .find(c=>c.startsWith('order='))
          .split('=')[1];
        const booking = Object.assign(
          {},
          JSON.parse(decodeURIComponent(raw)),
          formData
        );
        fetch('/api/customer_sales_intent_email',{
          method:'POST',
          body: JSON.stringify(booking)
        })
        .then(()=> window.location.href = paymentUrl);
      });
    }

  });
})(jQuery);