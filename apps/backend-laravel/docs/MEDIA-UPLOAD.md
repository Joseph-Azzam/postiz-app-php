# Media upload (POST /api/media/upload-server)

## Feature parity with NestJS

- **Endpoint:** `POST /api/media/upload-server`
- **Content-Type:** `multipart/form-data`
- **Field name:** `file` (Uppy XHRUpload default)
- **Response:** `{ id, name, path, url, thumbnail, alt }` (same shape as NestJS `saveFile`)

## If you get 400 Bad Request

1. **Check the response body**  
   The API now returns:
   - `error: "No file in request"` with `hint` listing input/file keys → request reached Laravel but no file was present.
   - `error: "File upload failed"` with `message` (e.g. PHP upload error) → file was present but invalid.

2. **PHP limits (WAMP / shared hosting)**  
   In `php.ini` ensure:
   - `upload_max_filesize` ≥ 30M (images) or 1G if you allow large videos.
   - `post_max_size` ≥ same or higher than `upload_max_filesize`.  
   If the upload exceeds these, PHP may not populate `$_FILES` and you get "No file in request".

3. **Storage link**  
   For public URLs to work, run once:
   ```bash
   cd apps/backend-laravel && php artisan storage:link
   ```
   This links `public/storage` → `storage/app/public`. Uploaded files are stored under `storage/app/public/media/`.

4. **CORS**  
   If the frontend is on another origin (e.g. `localhost:4200`), ensure `config/cors.php` allows that origin and `supports_credentials` is `true` when using cookies.
