<?php
/**
 * fix_documents.php
 * 
 * A self-contained PHP tool to perform search and replace operations in .docx files.
 * Users upload a Word document and provide a list of REPLACE/WITH pairs.
 */

// --- Helper Functions ---

/**
 * Parses the search and replace content from the textarea.
 * Validates that there are no newlines within the search/replace strings.
 */
function parseSearchReplace($input, &$error) {
    $lines = explode("\n", str_replace("\r", "", $input));
    $pairs = [];
    $tempSearch = null;
    $error = '';
    
    foreach ($lines as $line) {
        // We want to preserve trailing whitespace in the actual search/replace string if it's there,
        // but the keyword identification should be flexible.
        // However, the requirement says "Require only that the strings 'REPLACE:' and 'WITH:' be in upper case, on a new line, and with the colon exactly as shown"
        
        $trimmedLine = trim($line);
        if ($trimmedLine === '') continue;
        
        if (preg_match('/^\s*REPLACE:\s*(.*)$/', $line, $matches)) {
            if ($tempSearch !== null) {
                $error = "Found a new 'REPLACE:' before the previous one was matched 'WITH:'. Each 'REPLACE:' must be followed by a 'WITH:'.";
                return [];
            }
            $tempSearch = $matches[1];
        } elseif (preg_match('/^\s*WITH:\s*(.*)$/', $line, $matches)) {
            if ($tempSearch === null) {
                $error = "Found 'WITH:' without a preceding 'REPLACE:'.";
                return [];
            }
            $pairs[] = [
                'search' => $tempSearch,
                'replace' => $matches[1]
            ];
            $tempSearch = null;
        } else {
            // If it's not a keyword line and not empty, check if we're in the middle of a pair
            if ($tempSearch !== null) {
                $error = "Newlines are not allowed within the search or replace content. Invalid line: \"" . htmlspecialchars($line) . "\"";
                return [];
            }
            // Lines outside of REPLACE/WITH pairs are ignored (forgiven)
        }
    }
    
    if ($tempSearch !== null) {
        $error = "The final 'REPLACE:' is missing its 'WITH:' counterpart.";
        return [];
    }
    
    return $pairs;
}

/**
 * Processes the .docx file by searching and replacing strings in its XML components.
 */
function processDocx($filePath, $pairs) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        return false;
    }
    
    // Target parts of the .docx that usually contain text
    $xmlTargets = [
        'word/document.xml',
        'word/header1.xml', 'word/header2.xml', 'word/header3.xml',
        'word/footer1.xml', 'word/footer2.xml', 'word/footer3.xml',
        'word/footnotes.xml', 'word/endnotes.xml'
    ];
    
    $modifiedAny = false;
    foreach ($xmlTargets as $target) {
        $content = $zip->getFromName($target);
        if ($content === false) continue;
        
        $originalContent = $content;
        
        // We use a regex callback to ensure we only replace text inside <w:t> (text) tags,
        // which prevents accidentally breaking the XML structure or tag attributes.
        $content = preg_replace_callback('/(<w:t[^>]*>)(.*?)(<\/w:t>)/s', function($matches) use ($pairs) {
            $prefix = $matches[1];
            $text   = $matches[2];
            $suffix = $matches[3];
            
            foreach ($pairs as $pair) {
                $search = $pair['search'];
                $replace = $pair['replace'];
                
                if ($search === '') continue;
                
                // Word XML escapes <, >, and & in text nodes.
                $searchXML  = htmlspecialchars($search, ENT_XML1, 'UTF-8');
                $replaceXML = htmlspecialchars($replace, ENT_XML1, 'UTF-8');
                
                $text = str_replace($searchXML, $replaceXML, $text);
            }
            return $prefix . $text . $suffix;
        }, $content);
        
        if ($content !== $originalContent) {
            $zip->addFromString($target, $content);
            $modifiedAny = true;
        }
    }
    
    $zip->close();
    return true;
}

// --- Logic Execution ---

$error = '';
$sr_content = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) === 'fix_documents.php') {
    $sr_content = isset($_POST['sr_content']) ? $_POST['sr_content'] : '';
    
    $pairs = parseSearchReplace($sr_content, $error);
    
    if (empty($error) && empty($pairs)) {
        $error = "Please enter at least one REPLACE: and WITH: pair.";
    }
    
    if (empty($error)) {
        if (!isset($_FILES['docx_file']) || $_FILES['docx_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please select a valid .docx or .zip file to upload.";
        } else {
            $originalName = $_FILES['docx_file']['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            if ($ext !== 'docx' && $ext !== 'zip') {
                $error = "The uploaded file must be a .docx or .zip file.";
            } else {
                $uploadedTmp = $_FILES['docx_file']['tmp_name'];
                
                if ($ext === 'docx') {
                    // Create a temporary copy to process
                    $tempFile = tempnam(sys_get_temp_dir(), 'docx_fix');
                    if (copy($uploadedTmp, $tempFile)) {
                        if (processDocx($tempFile, $pairs)) {
                            $fixedName = pathinfo($originalName, PATHINFO_FILENAME) . " (fixed).docx";
                            
                            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fixedName) . '"');
                            header('Content-Length: ' . filesize($tempFile));
                            header('Pragma: no-cache');
                            header('Expires: 0');
                            
                            readfile($tempFile);
                            unlink($tempFile);
                            exit;
                        } else {
                            $error = "Could not process the Word document. It might be corrupted or protected.";
                            unlink($tempFile);
                        }
                    } else {
                        $error = "Internal server error: Could not create temporary file.";
                    }
                } elseif ($ext === 'zip') {
                    $tempZip = processZip($uploadedTmp, $pairs);
                    if ($tempZip) {
                        $fixedName = pathinfo($originalName, PATHINFO_FILENAME) . " (fixed).zip";
                        
                        header('Content-Type: application/zip');
                        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fixedName) . '"');
                        header('Content-Length: ' . filesize($tempZip));
                        header('Pragma: no-cache');
                        header('Expires: 0');
                        
                        readfile($tempZip);
                        unlink($tempZip);
                        exit;
                    } else {
                        $error = "Could not process the ZIP file. It might be corrupted.";
                    }
                }
            }
        }
    }
}

/**
 * Processes a ZIP file containing .docx documents.
 * Returns the path to a new temporary ZIP file with the fixed documents.
 */
function processZip($zipPath, $pairs) {
    $inputZip = new ZipArchive();
    if ($inputZip->open($zipPath) !== TRUE) {
        return false;
    }
    
    $tempZipPath = tempnam(sys_get_temp_dir(), 'zip_fix');
    $outputZip = new ZipArchive();
    if ($outputZip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        $inputZip->close();
        return false;
    }
    
    $tempFilesToCleanup = [];
    
    for ($i = 0; $i < $inputZip->numFiles; $i++) {
        $fileName = $inputZip->getNameIndex($i);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // If it's a directory (ends with /), skip it or recreate it
        if (substr($fileName, -1) === '/') {
            $outputZip->addEmptyDir($fileName);
            continue;
        }

        $content = $inputZip->getFromIndex($i);
        if ($content === false) continue;
        
        if ($ext === 'docx') {
            $tempDocx = tempnam(sys_get_temp_dir(), 'docx_in_zip');
            file_put_contents($tempDocx, $content);
            
            if (processDocx($tempDocx, $pairs)) {
                $outputZip->addFile($tempDocx, $fileName);
                $tempFilesToCleanup[] = $tempDocx;
            } else {
                $outputZip->addFromString($fileName, $content);
                @unlink($tempDocx);
            }
        } else {
            // Add other files as-is
            $outputZip->addFromString($fileName, $content);
        }
    }
    
    $inputZip->close();
    $outputZip->close();
    
    // Cleanup temp docx files after ZIP is closed
    foreach ($tempFilesToCleanup as $f) {
        @unlink($f);
    }
    
    return $tempZipPath;
}

// --- HTML UI ---
if (basename($_SERVER['PHP_SELF']) !== 'fix_documents.php' && !isset($_SERVER['HTTP_HOST'])) {
    return;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Text Fixer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 40px auto; padding: 0 20px; background-color: #f0f4f8; }
        h1 { color: #2c3e50; border-bottom: 2px solid #cbd5e0; padding-bottom: 10px; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .instruction { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 15px; margin-bottom: 20px; font-size: 0.95em; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 8px; color: #4a5568; }
        input[type="file"] { display: block; width: 100%; padding: 10px; border: 1px dashed #a0aec0; border-radius: 4px; background: #f7fafc; cursor: pointer; }
        textarea { width: 100%; height: 250px; padding: 12px; border: 1px solid #cbd5e0; border-radius: 4px; font-family: "Courier New", Courier, monospace; font-size: 14px; box-sizing: border-box; resize: vertical; }
        button[type="submit"] { background-color: #48bb78; color: white; padding: 12px 28px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; transition: background 0.2s; }
        button[type="submit"]:hover { background-color: #38a169; }
        .clear-btn { background-color: #edf2f7; color: #4a5568; padding: 4px 10px; border: 1px solid #cbd5e0; border-radius: 4px; cursor: pointer; font-size: 0.8em; font-weight: normal; }
        .clear-btn:hover { background-color: #e2e8f0; }
        .error { color: #c53030; background: #fff5f5; border: 1px solid #feb2b2; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
        footer { margin-top: 40px; font-size: 0.85em; color: #718096; border-top: 1px solid #e2e8f0; padding-top: 20px; }
        code { background: #edf2f7; padding: 2px 4px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>

    <div style="text-align: center; margin-bottom: 20px;">
        <img src="diamond.png" alt="Syndorela Logo" style="max-height: 80px;">
    </div>

    <h1>Document Text Fixer</h1>

    <div class="card">
        <div class="instruction">
            <strong>How to use:</strong> 
            <ol>
                <li>Select a Microsoft Word <code>.docx</code> file or a <code>.zip</code> file containing documents.</li>
                <li>Enter one or more Search/Replace pairs in the textarea below.</li>
                <li>Click the button to process and download the modified document(s).</li>
            </ol>
            Format for Search and Replace:
            <pre style="margin-top:10px; background:#fff; padding:10px; border:1px solid #bee3f8; border-radius:4px;">REPLACE: Old text
WITH: New text
REPLACE: Another word
WITH: Better word</pre>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="docx_file">Select Word Document (.docx) or ZIP file (.zip):</label>
                <input type="file" name="docx_file" id="docx_file" accept=".docx,.zip" required>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px;">
                    <label for="sr_content" style="margin-bottom: 0;">Enter Search and Replace content here:</label>
                    <button type="button" id="clear_btn" class="clear-btn">Clear</button>
                </div>
                <textarea name="sr_content" id="sr_content" placeholder="REPLACE: search string&#10;WITH: replacement string"><?php echo htmlspecialchars($sr_content); ?></textarea>
            </div>

            <button type="submit">Fix and Download Doc</button>
        </form>
    </div>

    <footer>
        <p><strong>Notes:</strong> 
            <ul>
                <li>This tool only replaces strings that are <em>unbroken</em> in the document's internal XML structure.</li>
                <li>Formatting (bold, italic, colors) might break a string into multiple pieces internally, making it unsearchable by this simple tool.</li>
                <li>Headers, footers, footnotes, and endnotes are also processed.</li>
            </ul>
        </p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const textArea = document.getElementById('sr_content');
            const clearBtn = document.getElementById('clear_btn');
            const storageKey = 'fix_docs_sr_content';

            // Load persisted content if textarea is empty (initial GET request)
            if (!textArea.value) {
                const savedContent = localStorage.getItem(storageKey);
                if (savedContent) {
                    textArea.value = savedContent;
                }
            } else {
                // If it is NOT empty (PHP just populated it), save it to localStorage
                localStorage.setItem(storageKey, textArea.value);
            }

            // Save content on change
            textArea.addEventListener('input', function() {
                localStorage.setItem(storageKey, textArea.value);
            });

            // Clear functionality
            clearBtn.addEventListener('click', function() {
                if (confirm('Are you sure you want to clear the search and replace rules?')) {
                    textArea.value = '';
                    localStorage.removeItem(storageKey);
                }
            });
        });
    </script>

</body>
</html>
