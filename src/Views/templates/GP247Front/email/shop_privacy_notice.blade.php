{{--
    Emails of personal data requests (US-customer-privacy-customer-emails,
    US-personal-data-requests-admin-notify): one view for every message; the words are labels
    under email.privacy.<message>.* that the owner edits on the Languages screen.

    Variables:
    - $title, $body: texts, escaped here
    - $button (string|null), $url (string|null): one link, built on the configured domain

    @aidlc-unit customer-privacy
    @aidlc-story US-customer-privacy-customer-emails
    @aidlc-adr customer-privacy_self-service-surfaces
--}}
@extends('gp247-shop-front::email.shop_layout')

@section('main')
<h1 style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;color:#2f3133;font-size:19px;font-weight:bold;margin-top:0;text-align:left">{{ $title }}</h1>
<p style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;color:#74787e;font-size:16px;line-height:1.5em;margin-top:0;text-align:left">{{ $body }}</p>
@if (!empty($url) && !empty($button))
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;margin:30px auto;padding:0;text-align:center;width:100%">
    <tbody>
        <tr>
            <td align="center" style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box">
                <a href="{{ $url }}" class="button button-primary" style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;border-radius:3px;color:#fff;display:inline-block;text-decoration:none;background-color:#3097d1;border-top:10px solid #3097d1;border-right:18px solid #3097d1;border-bottom:10px solid #3097d1;border-left:18px solid #3097d1" target="_blank">{{ $button }}</a>
            </td>
        </tr>
    </tbody>
</table>
<p style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;color:#74787e;font-size:12px;line-height:1.5em;margin-top:0;text-align:left;word-break:break-all">{{ $url }}</p>
@endif
@endsection
