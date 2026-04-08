<?php
/**
 * fix_addresses.php
 * 
 * A self-contained PHP page that accepts pasted spreadsheet clipboard data (TSV),
 * parses it, and returns a downloaded CSV file with normalized address fields.
 * 
 * The parser handles quoted fields with embedded newlines, which is common
 * when copying from spreadsheet cells that contain multiple lines.
 */

// --- Helper Functions ---

/**
 * Parses the final city, state, zip line of an address.
 * Expects format like "CITY NAME ST 12345" or "CITY NAME, ST 12345-6789".
 */
function parseCityStateZip($line) {
    $line = trim($line);
    
    // Regex explanation:
    // ^(.+?)           : Group 1 (City) - Matches everything lazily from the start
    // [\s,]+           : Matches whitespace or comma separator
    // ([A-Z]{2})       : Group 2 (State) - Exactly two uppercase letters
    // \s+              : Matches whitespace
    // (\d{5}(?:-\d{4})?) : Group 3 (Zip) - 5 digits, optionally followed by dash and 4 digits
    // $                : End of string
    $pattern = '/^(.+?)[\s,]+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';
    
    if (preg_match($pattern, $line, $matches)) {
        return [
            'city'    => trim($matches[1], " \t\n\r\0\x0B,"), // Trim whitespace and commas
            'state'   => strtoupper($matches[2]),
            'zipcode' => $matches[3]
        ];
    }
    
    return ['city' => '', 'state' => '', 'zipcode' => ''];
}

/**
 * Normalizes an address block into address, address2, city, state, zipcode.
 */
function normalizeAddress($addressBlock) {
    // Normalize line endings and split into trimmed non-empty lines
    $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", "", $addressBlock)))));
    
    $result = [
        'address'  => '',
        'address2' => '',
        'city'     => '',
        'state'    => '',
        'zipcode'  => ''
    ];
    
    $count = count($lines);
    if ($count === 0) return $result;

    // Last line is always treated as city/state/zip
    $cszLine = $lines[$count - 1];
    $cszParsed = parseCityStateZip($cszLine);
    
    $result['city']    = $cszParsed['city'];
    $result['state']   = $cszParsed['state'];
    $result['zipcode'] = $cszParsed['zipcode'];

    if ($count === 1) {
        // If only one line, we assumed it's CSZ, but if parsing failed, put it in address
        if (empty($result['city'])) {
            $result['address'] = $lines[0];
        }
    } elseif ($count === 2) {
        // Line 1 => address, Line 2 => CSZ
        $result['address'] = $lines[0];
    } elseif ($count === 3) {
        // Line 1 => address, Line 2 => address2, Line 3 => CSZ
        $result['address']  = $lines[0];
        $result['address2'] = $lines[1];
    } else {
        // More than 3 lines
        // First line => address
        // Middle lines => address2
        // Last line => CSZ
        $result['address'] = $lines[0];
        $middleLines = array_slice($lines, 1, -1);
        $result['address2'] = implode(", ", $middleLines);
    }
    
    return $result;
}

// --- Logic Execution ---

$error = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = isset($_POST['address_data']) ? trim($_POST['address_data']) : '';
    
    if (empty($input)) {
        $error = "Please paste some data before submitting.";
    } else {
        // Use a memory stream to parse TSV properly (handles quotes and newlines)
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $input);
        rewind($stream);
        
        $outputData = [];
        $headerSkipped = false;
        
        // fgetcsv parameters: stream, length, delimiter, enclosure
        while (($row = fgetcsv($stream, 0, "\t", '"')) !== false) {
            // Skip empty rows
            if (empty($row) || (count($row) === 1 && empty($row[0]))) continue;
            
            // Expected 2 columns: Name, Address
            $name = isset($row[0]) ? trim($row[0]) : '';
            $rawAddress = isset($row[1]) ? trim($row[1]) : '';
            
            // Check for header row
            if (!$headerSkipped) {
                if (stripos($name, 'AFFECTED OWNER NAMES') !== false || stripos($rawAddress, 'MAILING ADDRESS') !== false) {
                    $headerSkipped = true;
                    continue;
                }
            }
            
            if (empty($name) && empty($rawAddress)) continue;
            
            $norm = normalizeAddress($rawAddress);
            
            $outputData[] = [
                'name'     => $name,
                'address'  => $norm['address'],
                'address2' => $norm['address2'],
                'city'     => $norm['city'],
                'state'    => $norm['state'],
                'zipcode'  => $norm['zipcode'],
            ];
        }
        fclose($stream);
        
        if (empty($outputData)) {
            $error = "No valid data found to parse.";
        } else {
            // Output CSV File
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=parsed_addresses.csv');
            
            $output = fopen('php://output', 'w');
            
            // Headers for the CSV
            fputcsv($output, ['name', 'address', 'address2', 'city', 'state', 'zipcode']);
            
            foreach ($outputData as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
            exit;
        }
    }
}

// --- HTML UI ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Address Fixer & Parser</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 40px auto; padding: 0 20px; background-color: #f9f9f9; }
        h1 { color: #2c3e50; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .instruction { background: #e8f4fd; border-left: 4px solid #3498db; padding: 15px; margin-bottom: 25px; border-radius: 4px; }
        textarea { width: 100%; height: 300px; padding: 12px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 14px; box-sizing: border-box; resize: vertical; }
        button { background-color: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; margin-top: 15px; }
        button:hover { background-color: #2980b9; }
        .error { color: #e74c3c; background: #fdf2f2; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        footer { margin-top: 40px; font-size: 0.9em; color: #7f8c8d; border-top: 1px solid #eee; padding-top: 20px; }
        code { background: #eee; padding: 2px 4px; border-radius: 3px; }
    </style>
</head>
<body>

    <div style="text-align: center; margin-bottom: 20px;">
        <img src="diamond.png" alt="Syndorela Logo" style="max-height: 80px;">
    </div>

    <h1>Address Fixer</h1>

    <div class="instruction">
        <strong>Instructions:</strong> Paste clipboard data copied directly from your spreadsheet (e.g., Excel or Google Sheets). 
        The data should have two columns: <strong>Name</strong> and <strong>Mailing Address</strong>. 
        Multiple lines within an address cell are supported.
    </div>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="address_data">Paste TSV Data Here:</label>
        <textarea name="address_data" id="address_data" placeholder="Copy columns from your spreadsheet and paste them here..."><?php echo isset($_POST['address_data']) ? htmlspecialchars($_POST['address_data']) : ''; ?></textarea>
        <button type="submit">Parse and Download CSV</button>
    </form>

    <footer>
        <p><strong>Parser Note:</strong> This tool uses <code>fgetcsv</code> with tab delimiters to respect quoted multiline cells. 
        Address splitting logic:
        <ul>
            <li>2 lines: [Line 1] &rarr; Address, [Line 2] &rarr; City/State/Zip</li>
            <li>3 lines: [Line 1] &rarr; Address, [Line 2] &rarr; Address2, [Line 3] &rarr; City/State/Zip</li>
            <li>4+ lines: [Line 1] &rarr; Address, [Middle lines] &rarr; Address2 (comma separated), [Last line] &rarr; City/State/Zip</li>
        </ul>
        You can tweak the regex in <code>parseCityStateZip()</code> to handle different address formats.
        </p>
    </footer>

</body>
</html>
