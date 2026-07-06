<?php namespace Renick\TailorCompanion\Classes\Api;

use BackendAuth;
use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Middleware\TokenAuth;
use Renick\TailorCompanion\Models\AuditLog;
use Response;
use System\Models\File as FileModel;

/**
 * FilesController handles attachment binaries.
 *
 * Upload flow: the app uploads first (multipart) and receives a file id,
 * then references that id in a fileupload field of a subsequent batch op —
 * EntryWriter attaches it to the record.
 */
class FilesController
{
    /**
     * upload stores a file (unattached until a batch op claims it).
     */
    const MAX_UPLOAD_BYTES = 52428800; // 50 MB

    /**
     * @var array allowedExtensions the app may upload (safe media/docs only —
     * no executable/script types)
     */
    protected array $allowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'heic',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf',
        'mp3', 'wav', 'm4a', 'mp4', 'mov', 'm4v', 'webm', 'zip',
    ];

    public function upload(Request $request)
    {
        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return Response::json([
                'error' => ['code' => 'validation', 'message' => 'A valid multipart "file" field is required.'],
            ], 422);
        }

        $upload = $request->file('file');

        if ($upload->getSize() > self::MAX_UPLOAD_BYTES) {
            return Response::json([
                'error' => ['code' => 'file_too_large', 'message' => 'The file exceeds the 50 MB limit.'],
            ], 422);
        }

        $extension = strtolower($upload->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedExtensions, true)) {
            return Response::json([
                'error' => ['code' => 'file_type', 'message' => "Files of type .{$extension} are not allowed."],
            ], 422);
        }

        // Not public: uploads stay protected until attached; the field's
        // protected setting is applied when EntryWriter attaches the file.
        $file = new FileModel;
        $file->data = $upload;
        $file->save();

        $token = $request->attributes->get(TokenAuth::REQUEST_TOKEN_KEY);
        AuditLog::record('upload', [
            'token_id' => $token?->id,
            'backend_user_id' => BackendAuth::getUser()?->id,
            'record_id' => (int) $file->id,
            'diff' => ['file' => ['from' => null, 'to' => $file->file_name]],
        ]);

        return Response::json([
            'data' => [
                'id' => (int) $file->id,
                'name' => (string) $file->file_name,
                'size' => (int) $file->file_size,
                'content_type' => (string) $file->content_type,
                'url' => (string) $file->getPath(),
            ],
        ], 201);
    }

    /**
     * download streams a file through the authenticated API (works for
     * protected storage too, unlike the public URL).
     */
    public function download(int $id)
    {
        $file = FileModel::find($id);

        // Only serve files the app legitimately owns: fresh unattached
        // uploads, or attachments on Tailor entry/repeater models. Never
        // expose arbitrary system_files (avatars, other plugins' data).
        if (!$file || !$this->isServable($file)) {
            return Response::json([
                'error' => ['code' => 'unknown_file', 'message' => 'No file with this id.'],
            ], 404);
        }

        return Response::file($file->getLocalPath(), [
            'Content-Type' => $file->content_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($file->file_name) . '"',
        ]);
    }

    /**
     * isServable — unattached upload, or attached to a Tailor entry/repeater.
     */
    protected function isServable(FileModel $file): bool
    {
        if ($file->attachment_type === null) {
            return true;
        }

        // Tailor uses compound morph classes: "Tailor\Models\EntryRecord@xc_<uuid>c".
        // Match the model portion before the '@'.
        $modelClass = explode('@', (string) $file->attachment_type, 2)[0];

        return $modelClass === \Tailor\Models\EntryRecord::class
            || is_subclass_of($modelClass, \Tailor\Models\EntryRecord::class)
            || $modelClass === \Tailor\Models\RepeaterItem::class;
    }
}
