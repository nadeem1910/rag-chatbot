<!DOCTYPE html>
<html>
<head>
    <title>RAG Chatbot</title>
</head>
<body style="max-width:600px; margin:40px auto; font-family:sans-serif;">
    <h2>Chat with RAG Bot</h2>

    <form method="POST" action="{{ route('chat.ask') }}">
        @csrf
        <textarea name="message" placeholder="Ask something..." 
                  style="width:100%; height:120px;"></textarea>
        <br><br>
        <button type="submit">Ask</button>
    </form>

    @if(isset($answer))
        <h3>Answer:</h3>
        <p>{{ $answer }}</p>
    @endif
</body>
</html>
