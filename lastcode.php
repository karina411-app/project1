$request->validate([
            'file' => 'required|file|mimes:pdf|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('file');
            $parser = new Parser();
            $pdf = $parser->parseFile($file->path());
            $text = $pdf->getText();

            // Begin transaction
            DB::beginTransaction();

            $lines = explode("\n", $text);
            $currentProduct = null;
            $currentCategory = '';
            $updatedCount = 0;
            $createdCount = 0;

            foreach ($lines as $line) {
                $line = trim($line);
                
                // Skip empty lines and header/footer/page text
                if (empty($line) || preg_match('/(Page|Updated|Tel|www\.|Exclusively|IMAGE|UNIT|PCS|CTN|WSP|SRP)/', $line)) {
                    continue;
                }

                // Check if line is a category header (all caps, no product code)
                if (preg_match('/^[A-Z\s]+$/', $line) && !preg_match('/(AFA|AIB|AIA|AOT|AOA|ASM|ABG)-\d{4}/', $line)) {
                    $currentCategory = trim($line);
                    continue;
                }

                // Check for item number at start of line
                if (preg_match('/(AFA|AIB|AIA|AOT|AOA|ASM|ABG)-\d{4}[a-z]?\s+(.+)/', $line, $matches)) {
                    // Save previous product if exists
                    if ($currentProduct && isset($currentProduct['wsp']) && isset($currentProduct['srp'])) {
                        $this->saveOrUpdateProduct($currentProduct, $updatedCount, $createdCount);
                    }

                    // Start new product
                    $currentProduct = [
                        'item_no' => $matches[1],
                        'description' => $matches[2],
                        'brand_id' => Brand::firstOrCreate(['name' => 'Athletico'])->id,
                        'category_id' => $this->getCategoryId($currentCategory)
                    ];
                }
                // Process pricing and unit information
                elseif ($currentProduct && preg_match('/(?:PC|SET)\s+(?:(\d+)\s+)?(\d+(?:,\d{3})*(?:\.\d{2})?)\s+(\d+(?:,\d{3})*(?:\.\d{2})?)$/', $line, $matches)) {
                    $currentProduct['unit'] = strpos($line, 'PC') !== false ? 'PC' : 'SET';
                    $currentProduct['pcs_per_ctn'] = isset($matches[1]) ? intval($matches[1]) : null;
                    $currentProduct['wsp'] = (float) str_replace(',', '', $matches[2]);
                    $currentProduct['srp'] = (float) str_replace(',', '', $matches[3]);
                    
                    // Save the product immediately after getting price information
                    if (isset($currentProduct['description']) && !empty($currentProduct['description'])) {
                        $this->saveOrUpdateProduct($currentProduct, $updatedCount, $createdCount);
                        $currentProduct = null;
                    }
                }
                // Append to description if not a pricing/unit line and we have a current product
                elseif ($currentProduct && !empty($line)) {
                    if (!empty($currentProduct['description'])) {
                        $currentProduct['description'] .= ' ';
                    }
                    $currentProduct['description'] .= $line;
                }
            }

            // Save the last product if it exists and has complete information
            if ($currentProduct && isset($currentProduct['wsp']) && isset($currentProduct['srp'])) {
                $this->saveOrUpdateProduct($currentProduct, $updatedCount, $createdCount);
            }

            DB::commit();
            return redirect()->route('products.index')
                ->with('success', "Import completed! Updated: $updatedCount, Created: $createdCount products");

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Import error: " . $e->getMessage());
            return redirect()->route('products.index')
                ->with('error', 'Error importing products: ' . $e->getMessage());
        }



        public function import(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:pdf|max:10240', // 10MB max
    ]);

    try {
        $file = $request->file('file');
        $parser = new Parser();
        $pdf = $parser->parseFile($file->path());
        $text = $pdf->getText();

        // Begin transaction
        DB::beginTransaction();

        $lines = explode("\n", $text);
        $currentProduct = null;
        $updatedCount = 0;
        $createdCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and header/footer text
            if (empty($line) || 
                preg_match('/(Page|Updated|Tel|www\.|Exclusively)/', $line) ||
                preg_match('/(ITEM CODE|DESCRIPTION|IMAGE|Unit|WSP|SRP)/', $line)) {
                continue;
            }

            // Check for item number pattern (both Athletico and Black Knight patterns)
            if (preg_match('/(BBS|BBR|BBA|BTA|BKA|AFA|AIB|AIA|AOT|AOA|ASM|ABG|AIV)-\d{4}[A-Za-z]?/', $line, $matches)) {
                // Save previous product if exists
                if ($currentProduct && isset($currentProduct['wsp']) && isset($currentProduct['srp'])) {
                    $this->saveOrUpdateProduct($currentProduct, $updatedCount, $createdCount);
                }

                // Extract item number and initial description
                $parts = preg_split('/\s+/', $line, 2);
                $brandName = $this->getBrandFromItemCode($parts[0]);
                $categoryName = $this->getCategoryFromItemCode($parts[0]);
                
                $currentProduct = [
                    'item_no' => $parts[0],
                    'brand_id' => Brand::firstOrCreate(['name' => $brandName])->id,
                    'category_id' => Category::firstOrCreate(['name' => $categoryName])->id,
                    'description' => isset($parts[1]) ? trim($parts[1]) : '',
                    'unit' => 'PC' // Default unit
                ];
            }
            // Look for price information and PCS/CTN with various formats
            elseif ($currentProduct) {
                // Handle Black Knight format (where values are separated by spaces)
                if (preg_match('/set\s+(\d+)\s+/', $line, $pcsCtnMatches)) {
                    $currentProduct['pcs_per_ctn'] = (int) $pcsCtnMatches[1];
                    $currentProduct['unit'] = 'SET';
                }
                // Handle Athletico format - Look specifically for PCS/CTN value
                elseif (preg_match('/PC\s+(\d+)\s+/', $line, $pcsCtnMatches) && !isset($currentProduct['wsp'])) {
                    $currentProduct['pcs_per_ctn'] = (int) $pcsCtnMatches[1];
                }

                // Pattern for prices with currency symbol and/or commas
                if (preg_match('/(?:₱)?(\d+(?:,\d{3})*(?:\.\d{2})?)\s+(?:₱)?(\d+(?:,\d{3})*(?:\.\d{2})?)\s*$/', $line, $matches)) {
                    // Only set prices if not already set and if there are exactly two numbers at the end of the line
                    if (!isset($currentProduct['wsp']) && count($matches) === 3) {
                        $currentProduct['wsp'] = (float) str_replace(',', '', $matches[1]);
                        $currentProduct['srp'] = (float) str_replace(',', '', $matches[2]);
                    }
                    
                    // Extract unit if present in the line and not already set as SET
                    if (preg_match('/\b(PC|SET|TUBE)\b/i', $line, $unitMatches) && $currentProduct['unit'] !== 'SET') {
                        $currentProduct['unit'] = strtoupper($unitMatches[1]);
                    }
                }
                // If line doesn't contain prices or unit info, append to description
                elseif (!preg_match('/^(IMAGE|UNIT|PCS|CTN|WSP|SRP)/', $line)) {
                    if (!empty($currentProduct['description'])) {
                        $currentProduct['description'] .= ' ';
                    }
                    $currentProduct['description'] .= trim($line);
                }
            }
        }

        // Save the last product if exists
        if ($currentProduct && isset($currentProduct['wsp']) && isset($currentProduct['srp'])) {
            $this->saveOrUpdateProduct($currentProduct, $updatedCount, $createdCount);
        }

        DB::commit();
        return redirect()->route('products.index')
            ->with('success', "Import completed! Updated: $updatedCount, Created: $createdCount products");

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('PDF Import Error: ' . $e->getMessage());
        return redirect()->route('products.index')
            ->with('error', 'Error importing products: ' . $e->getMessage());
    }
}









elseif ($currentProduct) {
                // Check for different unit types with their quantities
                if (preg_match('/\b(BOX|PC|SET|TUBE|JAR|SET|DZN|PACK|PCK|PR|PAIR)\s+(\d+)\s+[\d,]+\s+[\d,]+$/i', $line, $unitMatches)) {
                    $currentProduct['unit'] = strtoupper($unitMatches[1]);
                    $currentProduct['pcs_per_ctn'] = (int) $unitMatches[2];
                }
                // Check for BOX with standard format
                elseif (preg_match('/\bBOX\s+[\d,]+\s+[\d,]+$/i', $line)) {
                    $currentProduct['unit'] = 'BOX';
                }
                // Additional check for SET without standard format
                elseif (preg_match('/set\s+(\d+)\s+/i', $line, $setMatches)) {
                    $currentProduct['unit'] = 'SET';
                    $currentProduct['pcs_per_ctn'] = (int) $setMatches[1];
                }
                // Additional check for PACK without standard format
                elseif (preg_match('/\b(?:PCk|PACK)\s+(\d+)?\s*/i', $line, $packMatches)) {
                    $currentProduct['unit'] = 'PACK';
                    if (isset($packMatches[1])) {
                        $currentProduct['pcs_per_ctn'] = (int) $packMatches[1];
                    }
                }
                // Additional check for TUBE without standard format
                elseif (preg_match('/\b(?:TB|TUBE)\s+(\d+)?\s*/i', $line, $tubeMatches)) {
                    $currentProduct['unit'] = 'TUBE';
                    if (isset($tubeMatches[1])) {
                        $currentProduct['pcs_per_ctn'] = (int) $tubeMatches[1];
                    }
                }
                // Additional check for JAR without standard format
                elseif (preg_match('/jar\b/i', $line, $jarMatches)) {
                    $currentProduct['unit'] = 'JAR';
                    // If there's a number before 'jar', capture it for pcs_per_ctn
                    if (preg_match('/(\d+)\s*jar\b/i', $line, $pcsMatches)) {
                        $currentProduct['pcs_per_ctn'] = (int) $pcsMatches[1];
                    }
                }
                // Additional check for BOX without standard format
                elseif (preg_match('/box\s+(\d+)?\s*/i', $line, $boxMatches)) {
                    $currentProduct['unit'] = 'BOX';
                    if (isset($boxMatches[1])) {
                        $currentProduct['pcs_per_ctn'] = (int) $boxMatches[1];
                    }
                    
                    else {
                        $currentProduct['unit'] = ''; // Leave the unit field blank
                    }
                
                }

                
                // Ensure the unit field remains blank if no match is found
              
                // elseif(preg_match('/jar\b/i', $line, $jarMatches)) {
                //     $currentProduct['unit'] = 'JAR';
                //     // If there's a number before 'jar', capture it for pcs_per_ctn
                //     if(preg_match('/(\d+)\s*jar\b/i', $line, $pcsMatches)) {
                //         $currentProduct['pcs_per_ctn'] = (int) $pcsMatches[1];
                //     }