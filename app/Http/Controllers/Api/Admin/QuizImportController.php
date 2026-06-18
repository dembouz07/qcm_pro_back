<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\QuizCreator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class QuizImportController extends Controller
{
    public function store(Request $request, QuizCreator $creator)
    {
        $request->validate([
            'file' => [
                'required', 
                'file', 
                'mimes:csv,txt,json,doc,docx,pdf',
                'mimetypes:text/csv,text/plain,application/json,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/pdf',
                'max:10240'
            ],
            'title' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'school_class_id' => ['nullable', Rule::exists('school_classes', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        
        // Fallback: détecter l'extension depuis le type MIME si l'extension est vide
        if (empty($extension)) {
            $mimeType = $request->file('file')->getMimeType();
            $extension = match($mimeType) {
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/msword' => 'doc',
                'application/pdf' => 'pdf',
                'application/json', 'text/json' => 'json',
                'text/csv', 'text/plain' => 'csv',
                default => 'csv'
            };
        }

        $data = match ($extension) {
            'json' => $this->parseJson($request),
            'pdf' => $this->parsePdf($request),
            'doc', 'docx' => $this->parseWord($request),
            default => $this->parseCsv($request),
        };

        $data = array_merge($data, array_filter([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'school_class_id' => $request->input('school_class_id'),
            'starts_at' => $request->input('starts_at'),
            'ends_at' => $request->input('ends_at'),
        ], fn ($value) => $value !== null && $value !== ''));

        $data = Validator::make($data, [
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.body' => ['required', 'string'],
            'questions.*.points' => ['nullable', 'integer', 'min:1', 'max:100'],
            'questions.*.choices' => ['required', 'array', 'min:2'],
            'questions.*.choices.*.body' => ['required', 'string'],
            'questions.*.choices.*.is_correct' => ['required', 'boolean'],
        ])->validate();

        $data['school_class_id'] = (int) $data['school_class_id'];
        $data['description'] = $data['description'] ?? null;
        $data['ends_at'] = $data['ends_at'] ?? null;

        $quiz = $creator->createWithQuestions($data, $request->user());

        return response()->json($quiz, 201);
    }

    private function parseJson(Request $request): array
    {
        $content = file_get_contents($request->file('file')->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw ValidationException::withMessages([
                'file' => 'Le fichier JSON est invalide.',
            ]);
        }

        if (!isset($data['questions']) || !is_array($data['questions'])) {
            throw ValidationException::withMessages([
                'file' => 'Le fichier JSON doit contenir un tableau questions.',
            ]);
        }

        return $data;
    }

    private function parseCsv(Request $request): array
    {
        $path = $request->file('file')->getRealPath();
        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'r');

        if (!$handle) {
            throw ValidationException::withMessages(['file' => 'Impossible de lire le fichier CSV.']);
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            throw ValidationException::withMessages(['file' => 'Le fichier CSV est vide.']);
        }

        $headers = array_map(fn ($header) => $this->normalizeHeader($header), $headers);
        $requiredHeaders = ['question', 'choice', 'is_correct'];

        foreach ($requiredHeaders as $requiredHeader) {
            if (!in_array($requiredHeader, $headers, true)) {
                throw ValidationException::withMessages([
                    'file' => "La colonne $requiredHeader est obligatoire.",
                ]);
            }
        }

        $grouped = [];
        $line = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if (count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $row = array_pad($row, count($headers), null);
            $record = array_combine($headers, array_slice($row, 0, count($headers)));

            $questionText = trim((string) Arr::get($record, 'question'));
            $choiceText = trim((string) Arr::get($record, 'choice'));

            if ($questionText === '' || $choiceText === '') {
                throw ValidationException::withMessages([
                    'file' => "Ligne $line : question et choice sont obligatoires.",
                ]);
            }

            if (!isset($grouped[$questionText])) {
                $grouped[$questionText] = [
                    'body' => $questionText,
                    'points' => (int) (Arr::get($record, 'points') ?: 1),
                    'choices' => [],
                ];
            }

            $grouped[$questionText]['choices'][] = [
                'body' => $choiceText,
                'is_correct' => $this->toBoolean(Arr::get($record, 'is_correct')),
            ];
        }

        fclose($handle);

        if (count($grouped) < 1) {
            throw ValidationException::withMessages(['file' => 'Aucune question trouvée dans le CSV.']);
        }

        return [
            'questions' => array_values($grouped),
        ];
    }

    private function detectDelimiter(string $path): string
    {
        $firstLine = (string) fgets(fopen($path, 'r'));
        $candidates = [',', ';', "\t"];
        $counts = array_map(fn ($delimiter) => substr_count($firstLine, $delimiter), $candidates);
        $maxIndex = array_keys($counts, max($counts))[0];

        return $candidates[$maxIndex];
    }

    private function normalizeHeader(?string $header): string
    {
        $header = strtolower(trim((string) $header));
        $header = str_replace([' ', '-'], '_', $header);

        return match ($header) {
            'choix', 'reponse', 'réponse', 'answer' => 'choice',
            'correct', 'bonne_reponse', 'bonne_réponse' => 'is_correct',
            'texte_question', 'question_text' => 'question',
            default => $header,
        };
    }

    private function toBoolean(mixed $value): bool
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'vrai', 'oui', 'yes', 'x'], true);
    }

    private function parseWord(Request $request): array
    {
        try {
            $path = $request->file('file')->getRealPath();
            $phpWord = WordIOFactory::load($path);
            
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $class = get_class($element);
                    
                    // Traiter les paragraphes
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . "\n";
                    }
                    // Traiter les TextRun et autres éléments textuels
                    elseif (method_exists($element, 'getElements')) {
                        foreach ($element->getElements() as $childElement) {
                            if (method_exists($childElement, 'getText')) {
                                $text .= $childElement->getText();
                            } elseif (method_exists($childElement, 'getContent')) {
                                $text .= $childElement->getContent();
                            }
                        }
                        $text .= "\n";
                    }
                    // Traiter les tables
                    elseif ($class === 'PhpOffice\PhpWord\Element\Table') {
                        foreach ($element->getRows() as $row) {
                            foreach ($row->getCells() as $cell) {
                                foreach ($cell->getElements() as $cellElement) {
                                    if (method_exists($cellElement, 'getText')) {
                                        $text .= $cellElement->getText() . " ";
                                    }
                                }
                            }
                            $text .= "\n";
                        }
                    }
                }
            }
            
            if (empty(trim($text))) {
                throw new \Exception('Le document Word semble vide ou ne contient pas de texte extractible.');
            }
            
            return $this->parseStructuredText($text);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'file' => 'Erreur Word: ' . $e->getMessage(),
            ]);
        }
    }

    private function parsePdf(Request $request): array
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($request->file('file')->getRealPath());
            $text = $pdf->getText();
            
            return $this->parseStructuredText($text);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'file' => 'Erreur lors de la lecture du fichier PDF: ' . $e->getMessage(),
            ]);
        }
    }

    private function parseStructuredText(string $text): array
    {
        $questions = [];
        $lines = explode("\n", $text);
        $currentQuestion = null;
        $currentChoices = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Ignore les titres (tout en majuscules, pas de ?)
            if (mb_strlen($line) > 10 && strtoupper($line) === $line && !str_contains($line, '?')) {
                continue;
            }
            
            // Détecte une question (contient ?)
            if (str_contains($line, '?')) {
                // Sauvegarder la question précédente
                if ($currentQuestion !== null && !empty($currentChoices)) {
                    $questions[] = $this->finalizeQuestion($currentQuestion, $currentChoices);
                }
                
                // Nettoyer la question
                $cleanQuestion = preg_replace('/^\d+[\.\)\:]?\s*/', '', $line);
                $cleanQuestion = preg_replace('/^Question\s+\d+\s*[\:\-\.]?\s*/i', '', $cleanQuestion);
                $currentQuestion = trim($cleanQuestion);
                $currentChoices = [];
            }
            // Détecte un choix avec plusieurs patterns
            else {
                $choice = $this->parseChoice($line);
                if ($choice !== null && $currentQuestion !== null) {
                    $currentChoices[] = $choice;
                }
            }
        }
        
        // Ajouter la dernière question
        if ($currentQuestion !== null && !empty($currentChoices)) {
            $questions[] = $this->finalizeQuestion($currentQuestion, $currentChoices);
        }
        
        if (empty($questions)) {
            throw ValidationException::withMessages([
                'file' => 'Aucune question détectée. Format: "1. Question ?" puis "[x] Choix" ou "A. Choix"'
            ]);
        }
        
        // Valider minimum 2 choix par question
        foreach ($questions as $index => $question) {
            if (count($question['choices']) < 2) {
                throw ValidationException::withMessages([
                    'file' => 'Question ' . ($index + 1) . ': minimum 2 choix requis (trouvé: ' . count($question['choices']) . ')'
                ]);
            }
        }
        
        return ['questions' => $questions];
    }
    
    private function parseChoice(string $line): ?array
    {
        // Pattern 1: [x] ou [ ]
        if (preg_match('/^\[([x\s\*✓☑])\]\s*(.+)$/i', $line, $matches)) {
            return [
                'body' => trim($matches[2]),
                'is_correct' => preg_match('/[x\*✓☑]/i', $matches[1]) ? true : false
            ];
        }
        
        // Pattern 2: A. ou A) avec ou sans espace
        if (preg_match('/^([A-Z])[\.\)]\s*(.+)$/i', $line, $matches)) {
            return [
                'body' => trim($matches[2]),
                'is_correct' => false // Par défaut, sera marqué correct si premier ou indiqué
            ];
        }
        
        // Pattern 3: ☑ ou ☐
        if (preg_match('/^[☑☐]\s*(.+)$/u', $line, $matches)) {
            return [
                'body' => trim($matches[1]),
                'is_correct' => str_starts_with($line, '☑')
            ];
        }
        
        // Pattern 4: Ligne commençant par un tiret ou bullet
        if (preg_match('/^[\-\•\*]\s*(.+)$/', $line, $matches)) {
            return [
                'body' => trim($matches[1]),
                'is_correct' => false
            ];
        }
        
        return null;
    }
    
    private function finalizeQuestion(string $questionText, array $choices): array
    {
        // Vérifier si au moins un choix est marqué correct
        $hasCorrect = false;
        foreach ($choices as $choice) {
            if ($choice['is_correct']) {
                $hasCorrect = true;
                break;
            }
        }
        
        // Si aucun n'est marqué, marquer le premier
        if (!$hasCorrect && !empty($choices)) {
            $choices[0]['is_correct'] = true;
        }
        
        return [
            'body' => $questionText,
            'points' => 1,
            'choices' => $choices
        ];
    }
}
