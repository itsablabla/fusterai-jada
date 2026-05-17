<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>How did we do?</title>
<style>
  body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
  .wrapper { max-width: 520px; margin: 40px auto; padding: 0 16px; }
  .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .header { background: #18181b; padding: 28px 32px; }
  .header h1 { margin: 0; color: #fff; font-size: 18px; font-weight: 600; }
  .body { padding: 32px; text-align: center; }
  .body p { margin: 0 0 8px; color: #52525b; font-size: 15px; line-height: 1.5; }
  .subject { font-size: 13px; color: #a1a1aa; margin-bottom: 28px !important; }
  .stars { display: flex; gap: 8px; justify-content: center; margin: 28px 0; }
  .star { display: inline-block; width: 52px; height: 52px; line-height: 52px; border-radius: 10px;
          font-size: 26px; text-decoration: none; background: #fafafa; border: 2px solid #e4e4e7;
          text-align: center; }
  .star:hover { border-color: #f59e0b; background: #fffbeb; }
  .labels { display: flex; justify-content: space-between; font-size: 11px; color: #a1a1aa; margin-top: -16px; }
  .footer { padding: 20px 32px; border-top: 1px solid #f4f4f5; text-align: center; }
  .footer p { margin: 0; font-size: 12px; color: #a1a1aa; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="header">
        <h1>{{ $conversation->mailbox?->name ?? config('app.name') }} Support</h1>
      </div>
      <div class="body">
        <p>Hi {{ $conversation->customer?->name ?? 'there' }},</p>
        <p>Your support request has been resolved. How would you rate your experience?</p>
        <p class="subject">Re: {{ $conversation->subject }}</p>

        <div class="stars">
          @foreach($ratingUrls as $score => $url)
            <a href="{{ $url }}" class="star" title="{{ $score }} star{{ $score > 1 ? 's' : '' }}">{{ $score }}★</a>
          @endforeach
        </div>
        <div class="labels">
          <span>Poor</span>
          <span>Excellent</span>
        </div>

        <p style="font-size:13px;color:#a1a1aa;margin-top:20px;">This link expires in 7 days.</p>
      </div>
      <div class="footer">
        <p>Powered by <a href="{{ config('app.url') }}" style="color:#a1a1aa;">{{ config('app.name') }}</a></p>
      </div>
    </div>
  </div>
</body>
</html>
