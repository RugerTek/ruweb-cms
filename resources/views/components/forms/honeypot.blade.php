{{--
    Anti-spam honeypot shared by every public form (docs/forms.md).
    Bots fill the hidden "website" field and submit instantly; humans never see it.
    The request base class (PublicFormRequest) reads both fields.
--}}
@php $hpId = 'hp-'.uniqid(); @endphp
<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
    <label for="{{ $hpId }}">Website</label>
    <input type="text" id="{{ $hpId }}" name="{{ \App\Http\Requests\Public\PublicFormRequest::HONEYPOT_FIELD }}" tabindex="-1" autocomplete="off" value="">
</div>
<input type="hidden" name="{{ \App\Http\Requests\Public\PublicFormRequest::TIMESTAMP_FIELD }}" value="{{ time() }}">
