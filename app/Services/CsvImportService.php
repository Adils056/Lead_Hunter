<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Suppression;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CsvImportService
{
    protected LeadScoringService $scoringService;
    protected EmailVerificationService $verificationService;

    public function __construct(LeadScoringService $scoringService, EmailVerificationService $verificationService)
    {
        $this->scoringService = $scoringService;
        $this->verificationService = $verificationService;
    }

    /**
     * Import rows from a parsed CSV array with user-mapped columns.
     */
    public function import(array $rows, array $columnMapping, ?int $userId = null): array
    {
        $userId = $userId ?? Auth::id();
        $importedCount = 0;
        $skippedCount = 0;
        $duplicateCount = 0;
        $errors = [];

        $csvSource = LeadSource::firstOrCreate(
            ['slug' => 'csv-importer'],
            ['name' => 'CSV Bulk Importer', 'provider_type' => 'csv_import', 'is_active' => true]
        );

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // +1 for 0-index, +1 for header row

            $fullName = trim($row[$columnMapping['full_name'] ?? ''] ?? '');
            $email = strtolower(trim($row[$columnMapping['email'] ?? ''] ?? ''));
            $companyName = trim($row[$columnMapping['company_name'] ?? ''] ?? '');
            $jobTitle = trim($row[$columnMapping['job_title'] ?? ''] ?? '');
            $country = trim($row[$columnMapping['country'] ?? ''] ?? '');
            $industry = trim($row[$columnMapping['industry'] ?? ''] ?? '');
            $website = trim($row[$columnMapping['website'] ?? ''] ?? '');
            $phone = trim($row[$columnMapping['phone'] ?? ''] ?? '');
            $service = trim($row[$columnMapping['service'] ?? ''] ?? '');

            // Fallback for first/last name
            $firstName = '';
            $lastName = '';
            if (!empty($fullName)) {
                $parts = explode(' ', $fullName, 2);
                $firstName = $parts[0] ?? '';
                $lastName = $parts[1] ?? '';
            }

            if (empty($email) && empty($fullName)) {
                $skippedCount++;
                continue;
            }

            // Check if email already exists (Deduplication)
            if (!empty($email) && Lead::where('email', $email)->exists()) {
                $duplicateCount++;
                continue;
            }

            // Check if suppressed
            if (!empty($email) && Suppression::isSuppressed($email)) {
                $skippedCount++;
                $errors[] = "Row {$rowNum}: Email {$email} is suppressed (do-not-contact).";
                continue;
            }

            DB::beginTransaction();
            try {
                // Find or create Company by domain or name
                $company = null;
                $domain = Company::extractDomain($website);
                if ($domain) {
                    $company = Company::where('domain', $domain)->first();
                }
                if (!$company && !empty($companyName)) {
                    $company = Company::where('name', $companyName)->first();
                }

                if (!$company && !empty($companyName)) {
                    $company = Company::create([
                        'name' => $companyName,
                        'website' => $website ?: null,
                        'domain' => $domain,
                        'industry' => $industry ?: 'General Business',
                        'country' => $country ?: 'Global',
                        'source_provider' => 'csv_importer',
                        'discovery_date' => now(),
                        'assigned_user_id' => $userId,
                    ]);
                }

                // Relevant services array
                $relevantServices = !empty($service) ? [$service] : ['Custom software development'];

                // Create Lead
                $lead = Lead::create([
                    'company_id' => $company?->id,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'full_name' => $fullName ?: ($firstName . ' ' . $lastName),
                    'job_title' => $jobTitle ?: 'Manager',
                    'email' => $email ?: null,
                    'phone' => $phone ?: null,
                    'country' => $country ?: ($company?->country ?? 'Global'),
                    'industry' => $industry ?: ($company?->industry ?? 'Technology'),
                    'relevant_services' => $relevantServices,
                    'lead_source_id' => $csvSource->id,
                    'source_name' => 'CSV Bulk Importer',
                    'discovery_date' => now(),
                    'lifecycle_stage' => 'new',
                    'assigned_user_id' => $userId,
                ]);

                // Verify email if provided
                if (!empty($email)) {
                    $this->verificationService->verifyEmail($email, $lead, $userId);
                }

                // Compute initial score
                $this->scoringService->evaluateLead($lead, $userId);

                DB::commit();
                $importedCount++;
            } catch (\Exception $e) {
                DB::rollBack();
                $errors[] = "Row {$rowNum} error: " . $e->getMessage();
            }
        }

        AuditLog::record('leads_csv_imported', null, null, [
            'imported' => $importedCount,
            'duplicates' => $duplicateCount,
            'skipped' => $skippedCount,
        ]);

        return [
            'imported' => $importedCount,
            'duplicates' => $duplicateCount,
            'skipped' => $skippedCount,
            'errors' => $errors,
        ];
    }
}
