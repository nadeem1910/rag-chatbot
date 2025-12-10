<!DOCTYPE html>
<html>
<head>
    <title>Upload Documents</title>
</head>
<body>
    <h1>Upload your files</h1>

    <form action="{{ route('upload.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <input type="file" name="files[]" multiple required>


        <button type="submit">Upload</button>
    </form>

</body>
</html>
