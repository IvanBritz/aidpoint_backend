<?php

namespace App\Http\Controllers;

use App\Models\FinancialAid;
use App\Models\FinancialAidDocument;
use App\Models\BeneficiaryDocumentSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Response;

class FinancialAidController extends Controller
{
    /**
     * Display a listing of financial aid facilities.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 8); // Default to 8 items per page
        $facilities = FinancialAid::with(['owner', 'documents'])
            ->where('isManagable', false) // Only show pending applications
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        
        return response()->json($facilities);
    }

    /**
     * Get current user's facilities.
     */
    public function myFacilities()
    {
        $user = Auth::user();
        $facilities = FinancialAid::with(['documents'])
            ->where('user_id', $user->id)
            ->get();
        return response()->json($facilities);
    }

    /**
     * Store a newly created facility registration.
     */
    public function store(Request $request)
    {
        $request->validate([
            'center_id' => 'required|string|max:50|unique:financial_aid,center_id',
            'center_name' => 'required|string|max:255',
            'longitude' => 'nullable|numeric|between:-180,180',
            'latitude' => 'nullable|numeric|between:-90,90',
            'description' => 'nullable|string|max:1000',
            'documents' => 'nullable|array',
            'documents.*.type' => 'required_with:documents|string',
            'documents.*.file' => 'required_with:documents|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        $user = Auth::user();

        // Check if user already has a facility
        $existingFacility = FinancialAid::where('user_id', $user->id)->first();
        if ($existingFacility) {
            return response()->json([
                'message' => 'You have already registered a facility. Each user can only register one facility.',
                'existing_facility' => $existingFacility
            ], 422);
        }

        $facility = FinancialAid::create([
            'user_id' => $user->id,
            'center_id' => $request->center_id,
            'center_name' => $request->center_name,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'description' => $request->description,
            'isManagable' => false, // Requires admin approval
        ]);

        // Handle document uploads
        if ($request->has('documents')) {
            foreach ($request->documents as $document) {
                $file = $document['file'];
                $path = $file->store('financial-aid-documents', 'public');
                
                FinancialAidDocument::create([
                    'financial_aid_id' => $facility->id,
                    'document_type' => $document['type'],
                    'document_path' => $path,
                ]);
            }
        }

        return response()->json([
            'message' => 'Facility registration submitted successfully. Awaiting admin approval.',
            'facility' => $facility->load('documents')
        ], 201);
    }

    /**
     * Display the specified facility.
     */
    public function show($id)
    {
        $facility = FinancialAid::with(['owner', 'documents'])->findOrFail($id);
        return response()->json($facility);
    }

    /**
     * Update the specified facility.
     */
    public function update(Request $request, $id)
    {
        $facility = FinancialAid::findOrFail($id);
        
        $request->validate([
            'center_name' => 'sometimes|required|string|max:255',
            'longitude' => 'nullable|numeric|between:-180,180',
            'latitude' => 'nullable|numeric|between:-90,90',
            'description' => 'nullable|string|max:1000',
        ]);
        
        $facility->update($request->only([
            'center_name', 'longitude', 'latitude', 'description'
        ]));
        
        return response()->json([
            'message' => 'Facility updated successfully',
            'facility' => $facility
        ]);
    }

    /**
     * Admin approve/reject facility.
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'isManagable' => 'required|boolean',
        ]);

        // Approve path: simply mark as manageable
        if ($request->boolean('isManagable')) {
            $facility = FinancialAid::findOrFail($id);
            $facility->update(['isManagable' => true]);

            return response()->json([
                'message' => 'Facility has been approved',
                'facility' => $facility
            ]);
        }

        // Reject path: delete facility, its documents, and the applicant account
        $facility = FinancialAid::with(['documents', 'owner'])->findOrFail($id);

        // Wrap in a transaction to avoid partial deletes
        \DB::transaction(function () use ($facility) {
            // Delete associated documents from storage and DB
            foreach ($facility->documents as $document) {
                Storage::disk('public')->delete($document->document_path);
            }
            $facility->documents()->delete();

            // Delete the facility
            $facility->delete();

            // Delete the applicant account (owner)
            if ($facility->owner) {
                // Also optionally remove their tokens/sessions if any (no FK constraints in Sanctum)
                $facility->owner->delete();
            }
        });

        return response()->json([
            'message' => 'Application rejected. The application and applicant account have been removed.'
        ]);
    }

    /**
     * Remove the specified facility.
     */
    public function destroy($id)
    {
        $facility = FinancialAid::findOrFail($id);
        
        // Delete associated documents from storage
        foreach ($facility->documents as $document) {
            Storage::disk('public')->delete($document->document_path);
        }
        
        $facility->delete();
        
        return response()->json(['message' => 'Facility deleted successfully']);
    }
    
    /**
     * Convert image to PDF format - Windows compatible approach.
     */
    private function convertImageToPdf($imagePath, $filename, $beneficiaryName = null, $documentType = 'Document', $enrolledSchool = null, $yearLevel = null, $schoolYear = null)
    {
        try {
            \Log::info('Starting Windows-compatible PDF conversion', ['file' => $imagePath, 'type' => $documentType]);
            
            // Get image info without loading the full image to avoid memory issues
            $imageInfo = getimagesize($imagePath);
            $imageWidth = $imageInfo[0] ?? 0;
            $imageHeight = $imageInfo[1] ?? 0;
            $imageMimeType = $imageInfo['mime'] ?? 'image/jpeg';
            
            \Log::info('Image info retrieved', [
                'width' => $imageWidth, 
                'height' => $imageHeight, 
                'mime' => $imageMimeType,
                'size' => filesize($imagePath)
            ]);
            
            // Create HTML: Page 1 with beneficiary info (Name, School, Year), Page 2 with image
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 24px; }
        body { font-family: Arial, sans-serif; color: #111827; }
        .header { text-align: center; margin-bottom: 18px; }
        .title { font-size: 22px; font-weight: bold; color: #1e40af; text-transform: uppercase; }
        .meta { font-size: 12px; color: #6b7280; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .info { display: grid; grid-template-columns: 1fr 2fr; gap: 8px; font-size: 14px; }
        .label { color: #374151; font-weight: 600; }
        .value { text-align: right; }
        .hint { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
        .divider { margin: 18px 0; border-top: 2px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">' . htmlspecialchars($documentType ?: 'Enrollment Verification') . '</div>
        <div class="meta">Generated: ' . date('F d, Y \\a\\t g:i A') . '</div>
    </div>

    <div class="card">
        <div class="info">
            <div class="label">Full Name</div>
            <div class="value">' . htmlspecialchars($beneficiaryName ?: '-') . '</div>
            <div class="label">Enrolled School</div>
            <div class="value">' . htmlspecialchars($enrolledSchool ?: '-') . '</div>
            <div class="label">Year Level</div>
            <div class="value">' . htmlspecialchars($yearLevel ?: '-') . '</div>
            <div class="label">School Year</div>
            <div class="value">' . htmlspecialchars($schoolYear ?: '-') . '</div>
        </div>
        <div class="hint">Turn to the next page to see the uploaded document image.</div>
    </div>

    <div class="divider"></div>

    <!-- Page Break -->
    <div style="page-break-before: always;"></div>

    <!-- Page 2: Document Image -->
    <div style="padding: 10px;">
        <div style="text-align: center; margin-bottom: 16px;">
            <h2 style="color: #1e40af; font-size: 18px; margin: 0;">Document Image</h2>
            <p style="margin: 6px 0 0 0; color: #6b7280; font-size: 12px;">Original upload for ' . htmlspecialchars($beneficiaryName ?: 'beneficiary') . '</p>
        </div>';
        
        // Add the image on page 2 with improved processing
        try {
            \Log::info('Processing image for page 2', ['path' => $imagePath]);
            
            // Check if GD extension is available
            if (!extension_loaded('gd')) {
                \Log::warning('GD extension not available, using direct file embedding');
                
                // Try to embed image directly without processing if it's reasonably sized
                $fileSize = filesize($imagePath);
                if ($fileSize < 1000000) { // Less than 1MB
                    $imageData = file_get_contents($imagePath);
                    $base64Image = base64_encode($imageData);
                    $mimeType = mime_content_type($imagePath);
                    
                    // Calculate display size based on original dimensions
                        $displayWidth = min($imageWidth, 450);
                        $displayHeight = intval($displayWidth * ($imageHeight / $imageWidth));
                        
                        if ($displayHeight > 600) {
                            $displayHeight = 600;
                            $displayWidth = intval($displayHeight * ($imageWidth / $imageHeight));
                        }
                        
                        $html .= '
        <div style="text-align: center; margin: 10px 0;">
            <div style="display: inline-block; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; background: white;">
                <img src="data:' . $mimeType . ';base64,' . $base64Image . '" style="width: ' . $displayWidth . 'px; height: ' . $displayHeight . 'px; display: block; border-radius: 6px;" alt="Document Image">
            </div>
        </div>';
                    \Log::info('Image successfully embedded directly in PDF', ['size' => $fileSize]);
                } else {
                    \Log::warning('Image too large for direct embedding', ['file_size' => $fileSize]);
                    $html .= '
        <div style="text-align: center; margin: 20px 0;">
            <div style="border: 3px dashed #f59e0b; border-radius: 12px; padding: 40px; background: #fffbeb; max-width: 450px; margin: 0 auto;">
                <div style="font-size: 64px; color: #f59e0b; margin-bottom: 20px;">📄</div>
                <h3 style="color: #92400e; margin: 0 0 15px 0; font-size: 18px;">Document Image Available</h3>
                <p style="color: #78350f; margin: 0; font-size: 14px; line-height: 1.5;">
                    <strong>Original dimensions:</strong> ' . $imageWidth . ' × ' . $imageHeight . ' pixels<br>
                    <strong>File size:</strong> ' . number_format($fileSize / 1024, 2) . ' KB<br>
                    <em>Image too large to embed in PDF. Document metadata is available on page 1.</em>
                </p>
            </div>
        </div>';
                }
            } else {
                // GD is available, use Intervention Image for better processing
                $manager = new ImageManager(new Driver());
                $image = $manager->read($imagePath);
                
                // Resize image to fit PDF page better
                $maxWidth = 450;
                $maxHeight = 600;
                
                $width = $image->width();
                $height = $image->height();
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                
                if ($ratio < 1) {
                    $newWidth = intval($width * $ratio);
                    $newHeight = intval($height * $ratio);
                    $image->resize($newWidth, $newHeight);
                    \Log::info('Image resized for PDF', ['from' => $width.'x'.$height, 'to' => $newWidth.'x'.$newHeight]);
                }
                
                // Convert to JPEG with good quality
                $imageData = $image->toJpeg(80);
                $base64Image = base64_encode($imageData);
                
                \Log::info('Image converted to base64', ['size' => strlen($base64Image), 'bytes' => strlen($imageData)]);
                
                // Be more generous with file size limit (2MB base64)
                if (strlen($base64Image) < 2000000) {
                    $html .= '
        <div style="text-align: center; margin: 20px 0;">
            <div style="display: inline-block; border: 3px solid #e2e8f0; border-radius: 12px; padding: 15px; background: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                <img src="data:image/jpeg;base64,' . $base64Image . '" style="max-width: 100%; height: auto; display: block; border-radius: 8px;" alt="Document Image">
            </div>
        </div>';
                    \Log::info('Image successfully embedded in PDF');
                } else {
                    \Log::warning('Image too large for PDF embedding', ['base64_size' => strlen($base64Image)]);
                    $html .= '
        <div style="text-align: center; margin: 20px 0;">
            <div style="border: 3px dashed #f59e0b; border-radius: 12px; padding: 40px; background: #fffbeb; max-width: 450px; margin: 0 auto;">
                <div style="font-size: 64px; color: #f59e0b; margin-bottom: 20px;">📄</div>
                <h3 style="color: #92400e; margin: 0 0 15px 0; font-size: 18px;">Image Too Large for PDF</h3>
                <p style="color: #78350f; margin: 0; font-size: 14px; line-height: 1.5;">
                    <strong>Original dimensions:</strong> ' . $imageWidth . ' × ' . $imageHeight . ' pixels<br>
                    <strong>File size:</strong> ' . number_format(filesize($imagePath) / 1024, 2) . ' KB<br>
                    <em>Document metadata is available on page 1</em>
                </p>
            </div>
        </div>';
                }
            }
        } catch (\Exception $imageError) {
            \Log::error('Image processing failed for page 2', ['error' => $imageError->getMessage(), 'trace' => $imageError->getTraceAsString()]);
            $html .= '
        <div style="text-align: center; margin: 20px 0;">
            <div style="border: 3px dashed #ef4444; border-radius: 12px; padding: 40px; background: #fef2f2; max-width: 450px; margin: 0 auto;">
                <div style="font-size: 64px; color: #ef4444; margin-bottom: 20px;">⚠️</div>
                <h3 style="color: #dc2626; margin: 0 0 15px 0; font-size: 18px;">Image Processing Error</h3>
                <p style="color: #991b1b; margin: 0; font-size: 14px; line-height: 1.5;">
                    Unable to process the uploaded image for display.<br>
                    <em>Complete document metadata is available on page 1</em>
                </p>
            </div>
        </div>';
        }
        
        $html .= '
        <div style="margin-top: 16px; text-align: center; color: #6b7280; font-size: 11px;">
            Page 2 of 2 — see page 1 for beneficiary details
        </div>
    </div>
</body>
</html>';
            
            \Log::info('Windows-compatible HTML generated', ['html_length' => strlen($html)]);
            
            // Generate PDF with minimal, reliable options
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isHtml5ParserEnabled', false); // Use legacy parser for better compatibility
            $options->set('dpi', 72); // Lower DPI for better compatibility
            $options->set('defaultFont', 'Arial');
            
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            
            // Get and verify output
            $output = $dompdf->output();
            if (empty($output) || strlen($output) < 1000) {
                throw new \Exception('PDF generation failed - output too small or empty');
            }
            
            \Log::info('Windows-compatible PDF generated successfully', ['size' => strlen($output)]);
            return $dompdf;
            
        } catch (\Exception $e) {
            \Log::error('Windows-compatible PDF conversion failed: ' . $e->getMessage(), [
                'file' => $imagePath,
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Get beneficiary info from document path.
     */
    private function getBeneficiaryFromPath($path)
    {
        try {
            // Extract beneficiary ID from path (beneficiary-documents/{id}/...)
            if (preg_match('/beneficiary-documents\/(\d+)\//i', $path, $matches)) {
                $beneficiaryId = $matches[1];
                return \App\Models\User::find($beneficiaryId);
            }
            return null;
        } catch (\Exception $e) {
            \Log::error('Failed to get beneficiary from path: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate proper filename based on beneficiary and document type.
     */
    private function generateFilename($beneficiary, $documentType, $originalFilename)
    {
        $extension = 'pdf';
        
        if ($beneficiary && $beneficiary->lastname && $beneficiary->firstname) {
            $lastname = preg_replace('/[^a-zA-Z0-9]/', '', $beneficiary->lastname);
            $firstname = preg_replace('/[^a-zA-Z0-9]/', '', $beneficiary->firstname);
            $docType = preg_replace('/[^a-zA-Z0-9]/', '', $documentType);
            
            return strtolower($lastname . '_' . $firstname . '_' . $docType . '.' . $extension);
        }
        
        // Fallback to original filename structure
        $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
        return $baseName . '_' . $documentType . '.' . $extension;
    }
    
    /**
     * Try to locate the BeneficiaryDocumentSubmission for a given storage path.
     */
    private function getSubmissionByPath($path)
    {
        try {
            return BeneficiaryDocumentSubmission::with('beneficiary')
                ->where('enrollment_certification_path', $path)
                ->orWhere('sao_photo_path', $path)
                ->orWhere('scholarship_certification_path', $path)
                ->latest()
                ->first();
        } catch (\Exception $e) {
            \Log::error('Failed to locate submission by path', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate a small placeholder PDF when image embedding fails (e.g., PNG without GD).
     */
    private function generateFallbackPdf($beneficiaryName, $documentType, $originalFilename, $reason = 'Image could not be embedded on this server.', $enrolledSchool = null, $yearLevel = null, $schoolYear = null)
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isHtml5ParserEnabled', false);
        $options->set('dpi', 72);
        $options->set('defaultFont', 'Arial');

        // Use the same layout as the normal generator: Page 1 details, Page 2 notice card
        $html = '<!DOCTYPE html>\n<html>\n<head>\n<meta charset="UTF-8">\n<style>\n  @page { margin: 24px; }\n  body { font-family: Arial, sans-serif; color: #111827; }\n  .header { text-align: center; margin-bottom: 18px; }\n  .title { font-size: 22px; font-weight: bold; color: #1e40af; text-transform: uppercase; }\n  .meta { font-size: 12px; color: #6b7280; }\n  .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-top: 16px; }\n  .info { display: grid; grid-template-columns: 1fr 2fr; gap: 8px; font-size: 14px; }\n  .label { color: #374151; font-weight: 600; }\n  .value { text-align: right; }\n  .hint { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }\n  .divider { margin: 18px 0; border-top: 2px solid #e5e7eb; }\n</style>\n</head>\n<body>\n  <div class="header">\n    <div class="title">' . htmlspecialchars($documentType ?: 'Document') . '</div>\n    <div class="meta">Generated: ' . date('F d, Y \\a\\t g:i A') . '</div>\n  </div>\n\n  <div class="card">\n    <div class="info">\n      <div class="label">Full Name</div>\n      <div class="value">' . htmlspecialchars($beneficiaryName ?: '-') . '</div>\n      <div class="label">Enrolled School</div>\n      <div class="value">' . htmlspecialchars($enrolledSchool ?: '-') . '</div>\n      <div class="label">Year Level</div>\n      <div class="value">' . htmlspecialchars($yearLevel ?: '-') . '</div>\n      <div class="label">School Year</div>\n      <div class="value">' . htmlspecialchars($schoolYear ?: '-') . '</div>\n      <div class="label">Original File</div>\n      <div class="value">' . htmlspecialchars($originalFilename ?: '-') . '</div>\n    </div>\n    <div class="hint">Turn to the next page to see the document image (if available).</div>\n  </div>\n\n  <div class="divider"></div>\n\n  <div style="page-break-before: always;"></div>\n\n  <div style="padding: 10px;">\n    <div style="text-align:center; margin-bottom:16px;">\n      <h2 style="color:#1e40af; font-size:18px; margin:0;">Document Image</h2>\n      <p style="margin:6px 0 0 0; color:#6b7280; font-size:12px;">Image unavailable on this server</p>\n    </div>\n    <div style="border:2px dashed #f59e0b; background:#fffbeb; border-radius:10px; padding:24px; max-width:520px; margin:0 auto; color:#92400e;">\n      <p style="margin:0 0 6px 0; font-weight:600;">Cannot embed the uploaded image</p>\n      <p style="margin:0; font-size:13px;">Reason: ' . htmlspecialchars($reason) . '. Enable the PHP GD or Imagick extension to embed PNG images, or upload a JPEG.</p>\n    </div>\n    <div style="margin-top:16px; text-align:center; color:#6b7280; font-size:11px;">Page 2 of 2 — see page 1 for beneficiary details</div>\n  </div>\n</body>\n</html>';

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * Serve document files with authentication check.
     */
    public function serveDocument($path)
    {
        // Verify user is authenticated
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        
        // Construct full path
        $fullPath = storage_path('app/public/' . $path);
        
        // Check if file exists
        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'Document not found'], 404);
        }
        
        // Get file info
        $mimeType = mime_content_type($fullPath);
        $filename = basename($fullPath);
        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Get related submission and beneficiary info
        $submission = $this->getSubmissionByPath($path);
        $beneficiary = $submission?->beneficiary ?: $this->getBeneficiaryFromPath($path);
        $yearLevel = $submission?->year_level;
        $enrollmentDate = $submission?->enrollment_date ? (string) $submission->enrollment_date : null;
        
        // Determine document type from path/filename
        $documentType = 'Document';
        if (strpos($path, 'enrollment_certification') !== false || strpos($filename, 'enrollment') !== false) {
            $documentType = 'Enrollment_Certification';
        } elseif (strpos($path, 'sao_photo') !== false || strpos($filename, 'sao') !== false) {
            $documentType = 'SOA';
        } elseif (strpos($path, 'scholarship') !== false || strpos($filename, 'scholar') !== false) {
            $documentType = 'Scholarship_Certification';
        }
        
        // Check if it's an image file; per request, just download the original image as attachment
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        
        if (in_array($fileExtension, $imageExtensions)) {
            // Build friendly filename with beneficiary info, preserving original extension
            $downloadName = $filename;
            if ($beneficiary && $beneficiary->lastname && $beneficiary->firstname) {
                $lastname = preg_replace('/[^a-zA-Z0-9]/', '', $beneficiary->lastname);
                $firstname = preg_replace('/[^a-zA-Z0-9]/', '', $beneficiary->firstname);
                $docType = preg_replace('/[^a-zA-Z0-9]/', '', $documentType);
                $downloadName = strtolower($lastname . '_' . $firstname . '_' . $docType . '.' . $fileExtension);
            }
            
            return response()->file($fullPath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]);
        }
        
        // For PDF files, trigger download directly with proper naming
        if ($fileExtension === 'pdf') {
            $pdfFilename = $this->generateFilename($beneficiary, $documentType, $filename);
            
            return response()->file($fullPath, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $pdfFilename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]);
        }
        
        // For other file types, download as attachment
        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
    }
}
