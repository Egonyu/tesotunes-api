<?php

namespace App\Http\Requests\Api\Kyc;

use App\Enums\KycDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('kyc.max_document_size_kb', 5120);
        $mimes = config('kyc.accepted_mime_types', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

        return [
            'document_type' => ['required', Rule::enum(KycDocumentType::class)],
            'document_number' => ['nullable', 'string', 'max:64'],
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                'mimetypes:'.implode(',', $mimes),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Document type is required.',
            'document_type.enum' => 'Unsupported document type. Accepted: national_id_front, national_id_back, selfie_with_id.',
            'file.required' => 'A document file is required.',
            'file.mimetypes' => 'File must be a JPEG, PNG, WebP image, or PDF.',
            'file.max' => 'File is too large (max :max KB).',
        ];
    }

    public function documentType(): KycDocumentType
    {
        return KycDocumentType::from($this->string('document_type')->toString());
    }
}
