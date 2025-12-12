<!DOCTYPE html>
<html>
<head>
    <title>RAG Chatbot - Ask Questions</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .chat-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 800px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        h2 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .messages-area {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9ff;
            border-radius: 15px;
            min-height: 300px;
            max-height: 500px;
        }
        .message {
            margin-bottom: 15px;
            padding: 15px 20px;
            border-radius: 15px;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .user-message {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-left: 50px;
            text-align: right;
        }
        .bot-message {
            background: white;
            color: #333;
            margin-right: 50px;
            border: 1px solid #e0e0e0;
            white-space: pre-wrap;
            line-height: 1.6;
        }
        .message-label {
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 5px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        form {
            display: flex;
            gap: 10px;
        }
        textarea {
            flex: 1;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            font-size: 15px;
            font-family: inherit;
            resize: none;
            height: 60px;
            transition: all 0.3s;
        }
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0 30px;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .nav-link {
            display: inline-block;
            margin-top: 15px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .nav-link:hover {
            text-decoration: underline;
        }
        .empty-state {
            text-align: center;
            color: #999;
            padding: 60px 20px;
        }
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #667eea;
        }
        .loading.active {
            display: block;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .stats {
            background: #e8f0fe;
            padding: 10px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #1967d2;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <h2>💬 RAG Chatbot</h2>
        <p class="subtitle">Ask questions about your uploaded documents</p>

        <div class="messages-area" id="messagesArea">
            @php
                $dbCount = DB::table('embeddings')->count();
            @endphp
            
            @if($dbCount > 0)
                <div class="stats">
                    📚 {{ $dbCount }} document chunks loaded and ready to answer questions!
                </div>
            @endif

            @if(session('query'))
                <div class="message user-message">
                    <div class="message-label">You</div>
                    {{ session('query') }}
                </div>
            @endif

            @if(session('answer'))
                <div class="message bot-message">
                    <div class="message-label">🤖 Assistant</div>
                    {{ session('answer') }}
                </div>
            @elseif(!session('query'))
                <div class="empty-state">
                    <div class="empty-state-icon">🤔</div>
                    <h3>Ask me anything!</h3>
                    <p style="margin-top: 10px;">I'll search through your uploaded documents to find the answer.</p>
                    @if($dbCount == 0)
                        <p style="margin-top: 15px; color: #ff6b6b; font-weight: 600;">
                            ⚠️ No documents uploaded yet. <a href="{{ url('/') }}" style="color: #667eea;">Upload documents first</a>
                        </p>
                    @endif
                </div>
            @endif
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <div>Thinking...</div>
            </div>
        </div>

        <form method="POST" action="{{ route('chat.ask') }}" id="chatForm" accept-charset="UTF-8">
            @csrf
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <textarea 
                name="message" 
                id="messageInput"
                placeholder="Type your question here (e.g., 'What is the main topic of the document?')..." 
                required
                autofocus
                minlength="3"
            >{{ old('message') }}</textarea>
            <button type="submit" id="submitBtn">Ask</button>
        </form>

        <div style="display: flex; gap: 15px; align-items: center; margin-top: 15px;">
            <a href="{{ url('/') }}" class="nav-link">← Upload More Documents</a>
            <a href="{{ route('chat.index') }}" class="nav-link">🔄 Clear Chat</a>
        </div>
    </div>

    <script>
        const form = document.getElementById('chatForm');
        const submitBtn = document.getElementById('submitBtn');
        const messageInput = document.getElementById('messageInput');
        const loading = document.getElementById('loading');
        const messagesArea = document.getElementById('messagesArea');

        // Auto-resize textarea
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 150) + 'px';
        });

        // Submit on Ctrl+Enter
        messageInput.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                form.submit();
            }
        });

        form.addEventListener('submit', function(e) {
            const message = messageInput.value.trim();
            
            console.log('Form submitting with message:', message);
            console.log('Message length:', message.length);
            
            if (!message || message.length < 3) {
                e.preventDefault();
                alert('Please enter a question (at least 3 characters)');
                return;
            }

            // Show loading
            loading.classList.add('active');
            
            // Disable form
            submitBtn.disabled = true;
            submitBtn.textContent = 'Thinking...';

            // DON'T disable textarea - it won't send data if disabled
            // messageInput.disabled = true;

            // Scroll to bottom
            messagesArea.scrollTop = messagesArea.scrollHeight;
        });

        // Scroll to bottom on load if there's an answer
        @if(session('answer'))
            setTimeout(() => {
                messagesArea.scrollTop = messagesArea.scrollHeight;
            }, 100);
        @endif
    </script>
</body>
</html>