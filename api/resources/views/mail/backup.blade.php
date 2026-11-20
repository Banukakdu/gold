{{-- AG-093 R8 / QA B5: backup emails. Plain lines; Blade escapes them for HTML. --}}
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $subject }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1f2933; line-height: 1.5;">
@foreach ($lines as $line)
<p style="margin: 0 0 10px; overflow-wrap: anywhere;">{{ $line }}</p>
@endforeach
<p style="margin: 16px 0 0; color: #6b7280; font-size: 12px;">{{ config('app.name') }}</p>
</body>
</html>
