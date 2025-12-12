<!DOCTYPE html>
<html>
<head>
    <title>Upload Documents - RAG Chatbot</title>
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
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .upload-area {
            border: 3px dashed #667eea;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            background: #f8f9ff;
            transition: all 0.3s;
            cursor: pointer;
        }
        .upload-area:hover {
            border-color: #764ba2;
            background: #f0f1ff;
        }
        .upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        input[type="file"] {
            display: none;
        }
        .file-label {
            display: block;
            margin-bottom: 20px;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 20px;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .file-list {
            margin-top: 20px;
            text-align: left;
        }
        .file-item {
            background: #f8f9ff;
            padding: 10px 15px;
            margin: 5px 0;
            border-radius: 8px;
            font-size: 14px;
            color: #333;
        }
        .supported-formats {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 13px;
            color: #666;
        }
        .nav-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .nav-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📚 RAG Chatbot</h1>
        <p class="subtitle">Upload your documents to start chatting</p>

        @if(session('success'))
            <div class="alert alert-success">
                ✅ {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-error">
                ❌ {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-error">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form action="{{ route('upload.store') }}" method="POST" enctype="multipart/form-data" id="uploadForm">
            @csrf

            <label for="fileInput" class="file-label">
                <div class="upload-area" id="uploadArea">
                    <div class="upload-icon">📁</div>
                    <h3>Click to upload or drag and drop</h3>
                    <p style="margin-top: 10px; color: #999;">PDF, TXT, MD, DOCX files</p>
                    <p style="margin-top: 5px; color: #999; font-size: 12px;">Max 10MB per file</p>
                </div>
            </label>

            <input type="file" name="files[]" id="fileInput" multiple accept=".txt,.pdf,.md,.docx" required>

            <div class="file-list" id="fileList"></div>

            <button type="submit" id="submitBtn">Upload Documents</button>
        </form>

        <div class="supported-formats">
            <strong>Supported formats:</strong> PDF, TXT, Markdown (.md), Word (.docx)
        </div>

        <a href="{{ url('/chat') }}" class="nav-link">💬 Go to Chat →</a>
    </div>

    <script>
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const uploadArea = document.getElementById('uploadArea');
        const submitBtn = document.getElementById('submitBtn');

        fileInput.addEventListener('change', function() {
            fileList.innerHTML = '';
            if (this.files.length > 0) {
                Array.from(this.files).forEach(file => {
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.textContent = `📄 ${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
                    fileList.appendChild(fileItem);
                });
            }
        });

        // Drag and drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#764ba2';
            uploadArea.style.background = '#f0f1ff';
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.style.borderColor = '#667eea';
            uploadArea.style.background = '#f8f9ff';
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#667eea';
            uploadArea.style.background = '#f8f9ff';
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        });

        // Prevent multiple submissions
        document.getElementById('uploadForm').addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';
        });
    </script>
</body>
</html>