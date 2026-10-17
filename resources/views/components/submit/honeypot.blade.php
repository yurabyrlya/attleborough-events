{{--
    SUB-04 honeypot and timing token (RESEARCH Pattern 7). The wrapper is
    hidden by .hp-field in app.css (no inline style) and from assistive tech
    by aria-hidden; the input is out of the tab order. The token is minted
    on every render, so a form shown again after an error gets a fresh one.
--}}
<div class="hp-field" aria-hidden="true">
    <label for="hp_fax">Leave this empty</label>
    <input id="hp_fax" name="{{ \App\Submissions\Honeypot::FIELD }}" type="text" tabindex="-1" autocomplete="off" value="">
</div>
<input type="hidden" name="{{ \App\Submissions\Honeypot::TOKEN }}" value="{{ \App\Submissions\Honeypot::mintToken() }}">
