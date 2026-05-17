<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Thanks for your feedback!</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
         background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
  .card { background: #fff; border-radius: 16px; padding: 48px 40px; text-align: center; max-width: 400px; width: 100%;
          box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  .emoji { font-size: 56px; margin-bottom: 16px; display: block; }
  h1 { margin: 0 0 8px; font-size: 22px; color: #18181b; font-weight: 700; }
  p  { margin: 0 0 4px; color: #71717a; font-size: 15px; line-height: 1.5; }
  .stars { font-size: 32px; margin: 20px 0 8px; letter-spacing: 2px; }
  .score { font-size: 14px; color: #a1a1aa; }
</style>
</head>
<body>
  <div class="card">
    @if($rating >= 4)
      <span class="emoji">😊</span>
      <h1>Glad we could help!</h1>
      <p>Thanks for the positive feedback. It means a lot to the team at {{ $mailboxName }}.</p>
    @elseif($rating === 3)
      <span class="emoji">😐</span>
      <h1>Thanks for your feedback.</h1>
      <p>We appreciate you taking the time to let us know. The team at {{ $mailboxName }} will use your feedback to improve.</p>
    @else
      <span class="emoji">😔</span>
      <h1>We're sorry to hear that.</h1>
      <p>Your feedback has been recorded. The team at {{ $mailboxName }} will use it to improve.</p>
    @endif

    <div class="stars">
      @for($i = 1; $i <= 5; $i++)
        {{ $i <= $rating ? '★' : '☆' }}
      @endfor
    </div>
    <p class="score">{{ $rating }}/5</p>
  </div>
</body>
</html>
