{{-- Sign-in code email (ported from legacy emails/auth/email_signin). Standalone, not the shared layout, as before. --}}
<!DOCTYPE html>
<html lang="en-US">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Sign-in code</title>
</head>
<body style="font-family: Georgia, 'Times New Roman', serif; font-size: 18px; line-height: 1.5; color: #222; max-width: 32em; margin: 0 auto; padding: 1.25rem;">
	<p style="margin-top: 0;">Your sign-in code is:</p>
	<p style="font-size: 2rem; font-weight: bold; letter-spacing: 0.35em; font-family: Consolas, 'Courier New', monospace; margin: 1rem 0;">{{ $code }}</p>
	<p style="margin: 1.5rem 0 0.75rem 0;">Tap the button to sign in (useful on a phone):</p>
	<p style="margin: 0 0 1.5rem 0;">
		<a href="{{ $signInUrl }}" target="_blank" rel="noopener noreferrer" style="display: inline-block; background: #0d6efd; color: #fff !important; text-decoration: none; padding: 14px 22px; border-radius: 8px; font-size: 18px; font-weight: 600; font-family: system-ui, -apple-system, sans-serif;">Sign in to Southeast Sharkfest</a>
	</p>
	<p style="font-size: 16px; color: #444;">Or enter the code on the website where you asked for it.</p>
	<p style="font-size: 14px; color: #666; margin-top: 2rem; word-break: break-all;">Having trouble with the button? Copy this address into your browser:<br><span style="font-family: monospace;">{{ $signInUrl }}</span></p>
</body>
</html>
