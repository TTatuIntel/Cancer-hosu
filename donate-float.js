/* ============================================================
   FLOATING DONATE BUTTON — donate-float.js
   Include on ALL pages alongside donate-float.css
   ============================================================ */
(function () {
    'use strict';

    /* ---------- Inject HTML ---------- */
    var wrap = document.createElement('div');
    wrap.className = 'floating-donate';
    wrap.innerHTML =
        '<div class="donate-trigger-wrap donate-trigger-wrap--fixed">' +
            '<button class="donate-button" aria-label="Support Us" onclick="window._dfpToggle()">' +
                '<span class="donate-icon">&#10084;</span> Support Us' +
            '</button>' +
            '<div class="donate-float-popup" id="donateFloatPopup">' +
                '<button class="dfp-close" onclick="window._dfpClose()" aria-label="Close">&times;</button>' +
                /* Step 1: Form */
                '<div id="dfpFormStep">' +
                    '<div class="dfp-modal-header"><h3>Support HOSU</h3></div>' +
                    '<p class="dfp-subtitle">Your donation transforms cancer care in Uganda.</p>' +
                    '<div class="dfp-form-grid">' +
                        '<div class="dfp-field dfp-fg-full">' +
                            '<label class="dfp-label">Full Name</label>' +
                            '<input class="dfp-input" id="dfpName" placeholder="Your full name" required>' +
                        '</div>' +
                        '<div class="dfp-field">' +
                            '<label class="dfp-label">Phone Number</label>' +
                            '<input class="dfp-input" type="tel" id="dfpPhone" placeholder="0772123456" maxlength="10" required>' +
                        '</div>' +
                        '<div class="dfp-field">' +
                            '<label class="dfp-label">Email</label>' +
                            '<input class="dfp-input" type="email" id="dfpEmail" placeholder="Your email">' +
                        '</div>' +
                        '<div class="dfp-invalid-phone dfp-fg-full" id="dfpInvalidPhone" style="display:none">Invalid phone number.</div>' +
                    '</div>' +
                    '<div class="dfp-field">' +
                        '<label class="dfp-label">Amount (UGX)</label>' +
                        '<input class="dfp-input" type="number" id="dfpCustomAmt" min="1000" placeholder="Enter amount">' +
                    '</div>' +
                    '<div class="dfp-amounts">' +
                        '<button type="button" class="dfp-amt" data-amt="5000">5,000</button>' +
                        '<button type="button" class="dfp-amt" data-amt="10000">10,000</button>' +
                        '<button type="button" class="dfp-amt" data-amt="25000">25,000</button>' +
                        '<button type="button" class="dfp-amt" data-amt="50000">50,000</button>' +
                    '</div>' +
                    '<div class="dfp-pay-divider">Payment Method</div>' +
                    '<div class="dfp-pay-chips">' +
                        '<button type="button" class="dfp-pay-chip" data-method="bank" onclick="window._dfpSelectPay(\'bank\')">' +
                            '<i class="fas fa-university"></i> Bank' +
                        '</button>' +
                        '<button type="button" class="dfp-pay-chip" data-method="banktobank" onclick="window._dfpSelectPay(\'banktobank\')">' +
                            '<i class="fas fa-exchange-alt"></i> Bank to Bank' +
                        '</button>' +
                        '<button type="button" class="dfp-pay-chip" data-method="visa" onclick="window._dfpSelectPay(\'visa\')">' +
                            '<i class="fab fa-cc-visa"></i> Visa' +
                        '</button>' +
                        '<button type="button" class="dfp-pay-chip dfp-mtn-chip" data-method="mtn" onclick="window._dfpSelectPay(\'mtn\')">' +
                            '<img src="img/mtn.png" alt="MTN" class="dfp-chip-img"> MTN' +
                        '</button>' +
                        '<button type="button" class="dfp-pay-chip dfp-airtel-chip" data-method="airtel" onclick="window._dfpSelectPay(\'airtel\')">' +
                            '<img src="img/airtel.png" alt="Airtel" class="dfp-chip-img"> Airtel' +
                        '</button>' +
                    '</div>' +
                    '<div id="dfpBankInfo" class="dfp-bank-info" style="display:none">' +
                        '<p><strong>Account:</strong> HOSU Limited</p>' +
                        '<p><strong>No:</strong> 9030025235214</p>' +
                        '<p><strong>Bank:</strong> Stanbic Bank, Mulago</p>' +
                    '</div>' +
                    '<button class="dfp-submit" id="dfpSubmitBtn" onclick="window._dfpSubmit()" disabled>Process Payment</button>' +
                '</div>' +
                /* Step 2: Processing */
                '<div id="dfpProcessStep" style="display:none">' +
                    '<div class="dfp-modal-header"><h3 id="dfpProcessTitle">Processing Payment</h3></div>' +
                    '<div class="dfp-steps">' +
                        '<div class="dfp-step"><div class="dfp-step-dot active" id="dfp-sd1">1</div><span>Register</span></div>' +
                        '<div class="dfp-step-line" id="dfp-sl1"></div>' +
                        '<div class="dfp-step"><div class="dfp-step-dot" id="dfp-sd2">2</div><span>Authorize</span></div>' +
                        '<div class="dfp-step-line" id="dfp-sl2"></div>' +
                        '<div class="dfp-step"><div class="dfp-step-dot" id="dfp-sd3">3</div><span>Receipt</span></div>' +
                    '</div>' +
                    '<div class="dfp-spinner" id="dfpSpinner"></div>' +
                    '<p class="dfp-process-msg" id="dfpMsg">Processing, please wait...</p>' +
                    '<p class="dfp-process-sub" id="dfpSubMsg"></p>' +
                    '<div id="dfpCountdown" style="display:none" class="dfp-countdown"></div>' +
                    '<button class="dfp-submit" id="dfpCloseProcess" style="display:none" onclick="window._dfpResetToForm()">Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(wrap);

    var _dfpSelectedMethod = null;
    var _dfpReceiptToken = null;
    var _dfpPaymentId = null;
    var _dfpTxnRef = null;
    var _dfpTxnId = null;
    var _dfpPollActive = false;

    /* ---------- Amount preset selection ---------- */
    wrap.addEventListener('click', function (e) {
        var btn = e.target.closest('.dfp-amt');
        if (!btn) return;
        wrap.querySelectorAll('.dfp-amt').forEach(function (b) { b.classList.remove('selected'); });
        btn.classList.add('selected');
        var input = document.getElementById('dfpCustomAmt');
        if (input) input.value = btn.getAttribute('data-amt');
        _dfpValidateForm();
    });

    /* ---------- Payment method selection ---------- */
    window._dfpSelectPay = function (method) {
        _dfpSelectedMethod = method;
        wrap.querySelectorAll('.dfp-pay-chip').forEach(function (c) { c.classList.remove('selected'); });
        var chip = wrap.querySelector('.dfp-pay-chip[data-method="' + method + '"]');
        if (chip) chip.classList.add('selected');
        var bankInfo = document.getElementById('dfpBankInfo');
        bankInfo.style.display = (method === 'bank' || method === 'banktobank') ? 'block' : 'none';
        _dfpValidateForm();
    };

    /* ---------- Real-time form validation ---------- */
    function _dfpValidateForm() {
        var name = document.getElementById('dfpName');
        var phone = document.getElementById('dfpPhone');
        var amt = document.getElementById('dfpCustomAmt');
        var nameOk = name && name.value.trim().length > 0;
        var phoneVal = phone ? phone.value.trim().replace(/\D/g, '') : '';
        var phoneOk = phoneVal.length === 10;
        var amtOk = amt && amt.value && Number(amt.value) >= 1000;
        var methodOk = !!_dfpSelectedMethod;
        // Show/hide phone error
        var phoneErr = document.getElementById('dfpInvalidPhone');
        if (phoneErr) {
            phoneErr.style.display = (phone && phone.value.trim().length > 0 && !phoneOk) ? 'block' : 'none';
        }
        document.getElementById('dfpSubmitBtn').disabled = !(nameOk && phoneOk && amtOk && methodOk);
    }

    /* ---------- Attach real-time listeners ---------- */
    ['dfpName', 'dfpPhone', 'dfpCustomAmt', 'dfpEmail'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', _dfpValidateForm);
    });

    /* ---------- Smart positioning based on viewport ---------- */
    function _dfpPositionPopup(popup) {
        // Remove existing position classes
        popup.classList.remove('pos-top', 'pos-bottom', 'pos-left');
        
        var btn = popup.closest('.donate-trigger-wrap').querySelector('.donate-button');
        if (!btn) return;
        
        var btnRect = btn.getBoundingClientRect();
        var viewportHeight = window.innerHeight;
        var viewportWidth = window.innerWidth;
        var popupHeight = 450; // estimated popup height
        var popupWidth = 320;
        
        var spaceAbove = btnRect.top;
        var spaceBelow = viewportHeight - btnRect.bottom;
        var spaceLeft = btnRect.left;
        
        // Determine best position
        if (spaceAbove >= popupHeight + 20) {
            // Enough space above - open upward
            popup.classList.add('pos-top');
        } else if (spaceBelow >= popupHeight + 20) {
            // Enough space below - open downward
            popup.classList.add('pos-bottom');
        } else if (spaceLeft >= popupWidth + 20) {
            // Open to the left
            popup.classList.add('pos-left');
        } else if (spaceBelow >= spaceAbove) {
            // More space below, use it even if not perfect
            popup.classList.add('pos-bottom');
        } else {
            // Default to top, will scroll if needed
            popup.classList.add('pos-top');
        }
    }

    /* ---------- Toggle / Close ---------- */
    window._dfpToggle = function () {
        var popup = document.getElementById('donateFloatPopup');
        if (popup) {
            var isActive = popup.classList.contains('active');
            if (!isActive) {
                // Close any other popups first
                if (typeof closeDonatePopups === 'function') closeDonatePopups();
                if (typeof closeLoginPopup === 'function') closeLoginPopup();
                // Position before showing
                _dfpPositionPopup(popup);
                // Scroll to top to show header
                popup.scrollTop = 0;
            }
            popup.classList.toggle('active');
        }
    };
    window._dfpClose = function () {
        var popup = document.getElementById('donateFloatPopup');
        if (popup) popup.classList.remove('active');
    };
    window._dfpResetToForm = function () {
        _dfpPollActive = false;
        document.getElementById('dfpFormStep').style.display = '';
        document.getElementById('dfpProcessStep').style.display = 'none';
        document.getElementById('dfpSpinner').style.display = '';
        document.getElementById('dfpCloseProcess').style.display = 'none';
        document.getElementById('dfpCountdown').style.display = 'none';
        document.getElementById('dfpMsg').style.color = '';
    };

    document.addEventListener('click', function (e) {
        var popup = document.getElementById('donateFloatPopup');
        if (popup && popup.classList.contains('active') && !e.target.closest('.floating-donate')) {
            popup.classList.remove('active');
        }
    });

    /* ---------- Step indicator ---------- */
    function _dfpSetStep(n) {
        [1,2,3].forEach(function(i) {
            var dot = document.getElementById('dfp-sd' + i);
            var line = document.getElementById('dfp-sl' + i);
            if (dot) dot.className = 'dfp-step-dot' + (i < n ? ' done' : i === n ? ' active' : '');
            if (line) line.className = 'dfp-step-line' + (i < n ? ' done' : i === n ? ' active' : '');
        });
    }

    function _dfpShowError(msg) {
        _dfpPollActive = false;
        document.getElementById('dfpSpinner').style.display = 'none';
        document.getElementById('dfpCountdown').style.display = 'none';
        document.getElementById('dfpMsg').textContent = msg;
        document.getElementById('dfpMsg').style.color = '#e63946';
        document.getElementById('dfpSubMsg').textContent = '';
        document.getElementById('dfpCloseProcess').style.display = '';
    }

    async function _dfpShowSuccess() {
        _dfpPollActive = false;
        _dfpSetStep(3);
        document.getElementById('dfpCountdown').style.display = 'none';
        document.getElementById('dfpMsg').textContent = 'Confirming payment…';
        document.getElementById('dfpMsg').style.color = '';
        document.getElementById('dfpSubMsg').textContent = '';

        // Server already confirmed + sent email via payment.php
        // Just call confirm_payment as a fallback
        if (_dfpReceiptToken && _dfpPaymentId) {
            try {
                var fd = new FormData();
                fd.append('payment_id', _dfpPaymentId);
                fd.append('receipt_token', _dfpReceiptToken);
                await fetch('api.php?action=confirm_payment', { method: 'POST', body: fd });
            } catch(e) {}
            document.getElementById('dfpSpinner').style.display = 'none';
            document.getElementById('dfpMsg').textContent = '\u2705 Donation confirmed!';
            document.getElementById('dfpMsg').style.color = '#27ae60';
            document.getElementById('dfpSubMsg').innerHTML = 'A receipt has been sent to your email.<br><br>'
                + '<a href="receipt.php?token=' + _dfpReceiptToken + '" '
                + 'style="display:inline-block;background:#0d4593;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.75rem;">'
                + '\uD83D\uDCC4 View Receipt</a>';
            _dfpResetForm();
        } else {
            document.getElementById('dfpSpinner').style.display = 'none';
            document.getElementById('dfpMsg').textContent = '\u2705 Thank you for your donation!';
            document.getElementById('dfpMsg').style.color = '#27ae60';
            document.getElementById('dfpCloseProcess').style.display = '';
            _dfpResetForm();
        }
    }

    function _dfpResetForm() {
        document.getElementById('dfpCustomAmt').value = '';
        document.getElementById('dfpName').value = '';
        document.getElementById('dfpEmail').value = '';
        document.getElementById('dfpPhone').value = '';
        var phoneErr = document.getElementById('dfpInvalidPhone');
        if (phoneErr) phoneErr.style.display = 'none';
        _dfpSelectedMethod = null;
        wrap.querySelectorAll('.dfp-amt').forEach(function (b) { b.classList.remove('selected'); });
        wrap.querySelectorAll('.dfp-pay-chip').forEach(function (c) { c.classList.remove('selected'); });
        document.getElementById('dfpBankInfo').style.display = 'none';
        document.getElementById('dfpSubmitBtn').disabled = true;
    }

    /* ---------- Token helper ---------- */
    // Removed: all gateway calls now go through payment.php server proxy

    function _dfpGenerateId() {
        return 'DON-' + Date.now() + '-' + Math.random().toString(36).substr(2, 6).toUpperCase();
    }

    /* ---------- Submit ---------- */
    window._dfpSubmit = async function () {
        var amt = document.getElementById('dfpCustomAmt');
        var name = document.getElementById('dfpName');
        var email = document.getElementById('dfpEmail');
        var phone = document.getElementById('dfpPhone');

        if (!name || !name.value.trim()) { alert('Please enter your full name.'); return; }
        if (!phone || !phone.value.trim()) { alert('Please enter your phone number.'); return; }
        var phoneVal = phone.value.trim().replace(/\D/g, '');
        if (phoneVal.length !== 10) { alert('Please enter a valid 10-digit phone number.'); return; }
        if (!amt || !amt.value || Number(amt.value) < 1000) { alert('Please enter an amount (min 1,000 UGX).'); return; }
        if (!_dfpSelectedMethod) { alert('Please select a payment method.'); return; }

        var amount = Number(amt.value);
        var nameVal = name.value.trim();
        var emailVal = (email && email.value.trim()) || '';

        // Bank or Bank-to-Bank — show details only
        if (_dfpSelectedMethod === 'bank' || _dfpSelectedMethod === 'banktobank') {
            document.getElementById('dfpFormStep').style.display = 'none';
            document.getElementById('dfpProcessStep').style.display = '';
            _dfpSetStep(1);
            document.getElementById('dfpSpinner').style.display = 'none';
            document.getElementById('dfpMsg').textContent = 'Transfer UGX ' + amount.toLocaleString() + ' to:';
            document.getElementById('dfpSubMsg').innerHTML = '<strong>HOSU Limited</strong><br>A/C: 9030025235214<br>Stanbic Bank, Mulago Branch<br><br>Contact us after transferring.';
            document.getElementById('dfpCloseProcess').style.display = '';
            return;
        }

        // Visa — placeholder
        if (_dfpSelectedMethod === 'visa') {
            document.getElementById('dfpFormStep').style.display = 'none';
            document.getElementById('dfpProcessStep').style.display = '';
            _dfpSetStep(1);
            document.getElementById('dfpSpinner').style.display = 'none';
            document.getElementById('dfpMsg').textContent = 'Visa card payments coming soon';
            document.getElementById('dfpSubMsg').innerHTML = 'Please use <strong>MTN Mobile Money</strong> or <strong>Airtel Money</strong> for instant payment.';
            document.getElementById('dfpCloseProcess').style.display = '';
            return;
        }

        // ── MTN or Airtel — full gateway flow via server proxy ──
        document.getElementById('dfpFormStep').style.display = 'none';
        document.getElementById('dfpProcessStep').style.display = '';
        _dfpSetStep(1);
        document.getElementById('dfpMsg').textContent = 'Preparing your donation…';
        document.getElementById('dfpSubMsg').textContent = '';
        document.getElementById('dfpCountdown').style.display = 'none';
        document.getElementById('dfpCloseProcess').style.display = 'none';
        document.getElementById('dfpSpinner').style.display = '';
        _dfpReceiptToken = null;
        _dfpPaymentId = null;

        var methodLabel = _dfpSelectedMethod === 'mtn' ? 'MTN Mobile Money' : 'Airtel Money';

        // Step 1: Pre-register donation via api.php
        try {
            var preFd = new FormData();
            preFd.append('fullName', nameVal);
            preFd.append('email', emailVal);
            preFd.append('phone', phoneVal);
            preFd.append('profession', 'Donor');
            preFd.append('institution', '');
            preFd.append('paymentMethod', methodLabel);
            preFd.append('paymentType', 'donation');
            preFd.append('membershipPeriod', '');
            preFd.append('amount', amount);
            preFd.append('transactionId', '');
            preFd.append('transactionRef', '');
            var preRes = await fetch('api.php?action=pre_register', { method: 'POST', body: preFd }).then(function(r) { return r.json(); });
            if (preRes.success) {
                _dfpReceiptToken = preRes.receipt_token;
                _dfpPaymentId = preRes.payment_id;
            } else if (preRes.error) {
                _dfpShowError(preRes.error);
                return;
            }
        } catch(e) {
            _dfpShowError('Connection error. Please check your network and try again.');
            return;
        }

        // Step 2: Send payment request through server proxy
        _dfpSetStep(2);
        document.getElementById('dfpMsg').textContent = 'Sending payment request to ' + (_dfpSelectedMethod === 'mtn' ? 'MTN' : 'Airtel') + '…';
        document.getElementById('dfpSubMsg').textContent = 'A payment prompt will appear on your phone';

        try {
            var payFd = new FormData();
            payFd.append('phone', phoneVal);
            payFd.append('amount', amount);
            payFd.append('payment_id', _dfpPaymentId || 0);

            var payAction = _dfpSelectedMethod === 'mtn' ? 'pay_mtn' : 'pay_airtel';
            var payRes = await fetch('payment.php?action=' + payAction, { method: 'POST', body: payFd }).then(function(r) { return r.json(); });

            if (payRes.error) {
                _dfpShowError(payRes.error);
                return;
            }

            _dfpTxnRef = payRes.txn_ref || null;
            _dfpTxnId = payRes.txn_id || null;

            if (payRes.status === 'completed') {
                _dfpShowSuccess();
            } else if (payRes.status === 'pending') {
                document.getElementById('dfpMsg').textContent = '\uD83D\uDCF1 Check your phone now!';
                document.getElementById('dfpSubMsg').textContent = payRes.message || 'Approve the payment on your phone';
                if (_dfpSelectedMethod === 'airtel') {
                    _dfpPollAirtel();
                } else {
                    _dfpPollMtn();
                }
            } else {
                _dfpShowError(payRes.message || 'Payment failed. Please try again.');
            }
        } catch(e) {
            _dfpShowError('Network error. Please check your connection and try again.');
        }
    };

    /* ---------- Airtel polling via server proxy ---------- */
    async function _dfpPollAirtel() {
        _dfpPollActive = true;
        var MAX_POLLS = 18, INTERVAL = 10000;
        document.getElementById('dfpMsg').textContent = 'Waiting for your approval…';
        document.getElementById('dfpSubMsg').textContent = 'Enter your Airtel Money PIN on your phone';

        for (var i = 0; i < MAX_POLLS; i++) {
            if (!_dfpPollActive) return;
            var secsLeft = (MAX_POLLS - i) * (INTERVAL / 1000);
            var cd = document.getElementById('dfpCountdown');
            if (cd) { cd.style.display = ''; cd.textContent = 'Time remaining: ' + Math.floor(secsLeft / 60) + 'm ' + (secsLeft % 60) + 's'; }

            await new Promise(function(r) { setTimeout(r, INTERVAL); });
            if (!_dfpPollActive) return;

            try {
                var params = 'action=check_airtel&txn_id=' + encodeURIComponent(_dfpTxnId || _dfpTxnRef)
                    + '&payment_id=' + (_dfpPaymentId || 0);
                var result = await fetch('payment.php?' + params).then(function(r) { return r.json(); });

                if (result.status === 'completed') {
                    _dfpPollActive = false; _dfpShowSuccess(); return;
                } else if (result.status === 'failed' || result.status === 'expired') {
                    _dfpPollActive = false; _dfpShowError(result.message || 'Payment was declined. Please try again.'); return;
                }
            } catch(e) {}
        }
        _dfpPollActive = false;
        _dfpShowError('Payment timed out. The transaction has been cancelled. If money was deducted, please contact HOSU support.');
    }

    /* ---------- MTN polling via server proxy ---------- */
    async function _dfpPollMtn() {
        _dfpPollActive = true;
        var MAX_POLLS = 12, INTERVAL = 10000;
        document.getElementById('dfpMsg').textContent = 'Waiting for your approval…';
        document.getElementById('dfpSubMsg').textContent = 'Approve the MTN Mobile Money prompt on your phone';

        for (var i = 0; i < MAX_POLLS; i++) {
            if (!_dfpPollActive) return;
            var secsLeft = (MAX_POLLS - i) * (INTERVAL / 1000);
            var cd = document.getElementById('dfpCountdown');
            if (cd) { cd.style.display = ''; cd.textContent = 'Time remaining: ' + Math.floor(secsLeft / 60) + 'm ' + (secsLeft % 60) + 's'; }

            await new Promise(function(r) { setTimeout(r, INTERVAL); });
            if (!_dfpPollActive) return;

            try {
                var params = 'action=check_mtn&txn_ref=' + encodeURIComponent(_dfpTxnRef)
                    + '&payment_id=' + (_dfpPaymentId || 0);
                var result = await fetch('payment.php?' + params).then(function(r) { return r.json(); });

                if (result.status === 'completed') {
                    _dfpPollActive = false; _dfpShowSuccess(); return;
                } else if (result.status === 'failed') {
                    _dfpPollActive = false; _dfpShowError(result.message || 'Payment failed. Please try again.'); return;
                }
            } catch(e) {}
        }
        _dfpPollActive = false;
        _dfpShowError('Payment timed out. The transaction has been cancelled. If money was deducted, please contact HOSU support.');
    }
})();
