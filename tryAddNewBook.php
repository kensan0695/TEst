<?php
    session_start();
    
    
    // Initialize variables
    $success_message = $error_message = '';
    $form_data = array();
    $ml_prediction = '';
    
    // Cutter Number Generator function based on LC Cutter Tables (G 63)
    function generateCutterNumber($text) {
        if(empty($text)) return '';
        
        // Clean and normalize the input text
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]/', '', $text);
        
        if(empty($text)) return '';
        
        $first_letter = $text[0];
        $cutter = strtoupper($first_letter);
        
        // Special handling for common prefixes
        if(strlen($text) > 2) {
            if(substr($text, 0, 2) == 'mc') {
                $first_letter = 'm';
                $cutter = 'M';
                $text = substr($text, 2);
            } else if(substr($text, 0, 3) == 'mac') {
                $first_letter = 'm';
                $cutter = 'M';
                $text = substr($text, 3);
            }
        }
        
        // Get the next letter for processing
        $next_letter = isset($text[1]) ? $text[1] : '';
        
        // Main Cutter logic based on G 63 tables
        // Initial vowels table (a, e, i, o, u)
        $vowels = ['a', 'e', 'i', 'o', 'u'];
        
        if(in_array($first_letter, $vowels)) {
            switch($next_letter) {
                case 'a': $cutter .= '2'; break;
                case 'b': $cutter .= '3'; break;
                case 'c': case 'd': $cutter .= '4'; break;
                case 'e': case 'f': $cutter .= '5'; break;
                case 'g': case 'h': $cutter .= '6'; break;
                case 'i': case 'j': $cutter .= '7'; break;
                case 'k': case 'l': $cutter .= '8'; break;
                case 'm': case 'n': case 'o': $cutter .= '9'; break;
                default: $cutter .= '1';
            }
        } else {
            // Initial consonants table
            switch($next_letter) {
                case 'a': $cutter .= '3'; break;
                case 'e': $cutter .= '4'; break;
                case 'i': $cutter .= '5'; break;
                case 'o': $cutter .= '6'; break;
                case 'r': $cutter .= '7'; break;
                case 'u': $cutter .= '8'; break;
                default: $cutter .= '2';
            }
        }
        
        // Add third digit based on the third letter if exists
        if(isset($text[2])) {
            $third_letter = $text[2];
            // Simple mapping for third letter
            $ord = ord($third_letter) - ord('a');
            $digit = intval(($ord / 26.0) * 9) + 1;
            $cutter .= $digit;
        } else {
            $cutter .= '1';
        }
        
        return $cutter;
    }
    
    // Function to call the ML model API
    function getPredictedClassNumber($title, $topics) {
        // Path to Python script
        $python_script = realpath(__DIR__ . '/../model-20250507T151504Z-1-001/model/app.py');
        
        if (!file_exists($python_script)) {
            return ['error' => 'Model script not found', 'path_checked' => $python_script];
        }
        
        // Prepare data for the model
        $data = [
            'title' => $title,
            'topics' => implode(', ', $topics)
        ];
        
        // Convert to JSON
        $json_data = json_encode($data);
        
        // Create a temporary file to store the input data
        $temp_input = tempnam(sys_get_temp_dir(), 'book_data_');
        file_put_contents($temp_input, $json_data);
        
        // Create a temporary file for the output
        $temp_output = tempnam(sys_get_temp_dir(), 'model_output_');
        
        // Command to run Python script
        // Assumes Python 3 is installed and accessible as 'python' or 'python3'
        $python_command = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
        $command = escapeshellcmd("$python_command \"$python_script\" \"$temp_input\" \"$temp_output\"");
        
        // Execute the command
        exec($command, $output, $return_code);
        
        // Check for execution errors
        if ($return_code !== 0) {
            return ['error' => 'Failed to execute model script', 'output' => $output];
        }
        
        // Read prediction from output file
        if (file_exists($temp_output)) {
            $prediction_json = file_get_contents($temp_output);
            $prediction = json_decode($prediction_json, true);
            
            // Clean up temporary files
            @unlink($temp_input);
            @unlink($temp_output);
            
            return $prediction;
        } else {
            return ['error' => 'No output generated from model'];
        }
    }
    
    // Handle form submissions
    if($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Process based on the requested action
        if(isset($_POST['action']) && $_POST['action'] == 'add_book') {
            // Collect form data
            $form_data = array(
                'title' => $_POST['title'],
                'publication_date' => !empty($_POST['publication_date']) ? $_POST['publication_date'] : 'Not specified',
                'authors' => array(),
                'topics' => array()
            );
            
            // Process authors
            if(isset($_POST['author_first_name']) && is_array($_POST['author_first_name'])) {
                $author_count = count($_POST['author_first_name']);
                $primary_author_last_name = '';
                
                for($i = 0; $i < $author_count; $i++) {
                    $first_name = $_POST['author_first_name'][$i];
                    $middle_name = isset($_POST['author_middle_name'][$i]) ? $_POST['author_middle_name'][$i] : '';
                    $last_name = $_POST['author_last_name'][$i];
                    
                    if(!empty($first_name) || !empty($last_name)) {
                        $author_name = trim("$first_name " . ($middle_name ? "$middle_name " : '') . "$last_name");
                        $form_data['authors'][] = $author_name;
                        
                        // Store the first author's last name for the Cutter number
                        if($i == 0 && !empty($last_name)) {
                            $primary_author_last_name = $last_name;
                        }
                    }
                }
                
                // Generate Cutter number based on primary author or title
                if(!empty($primary_author_last_name)) {
                    $cutter = generateCutterNumber($primary_author_last_name);
                    $form_data['cutter'] = '.' . $cutter;
                    $form_data['cutter_source'] = $primary_author_last_name;
                } else if(!empty($form_data['title'])) {
                    // If no author, use title for Cutter number
                    $title_words = explode(' ', $form_data['title']);
                    $first_significant_word = '';
                    
                    // Skip articles at the beginning (a, an, the)
                    $articles = array('a', 'an', 'the');
                    foreach($title_words as $word) {
                        if(!in_array(strtolower($word), $articles)) {
                            $first_significant_word = $word;
                            break;
                        }
                    }
                    
                    $cutter = generateCutterNumber($first_significant_word);
                    $form_data['cutter'] = '.' . $cutter;
                    $form_data['cutter_source'] = $first_significant_word;
                }
            }
            
            // Process topics
            if(isset($_POST['topic_name']) && is_array($_POST['topic_name'])) {
                $topic_count = count($_POST['topic_name']);
                
                for($i = 0; $i < $topic_count; $i++) {
                    $topic_name = $_POST['topic_name'][$i];
                    if(!empty($topic_name)) {
                        $form_data['topics'][] = $topic_name;
                    }
                }
            }
            
            // Get ML prediction if we have enough data
            if(!empty($form_data['title']) && !empty($form_data['topics'])) {
                $ml_result = getPredictedClassNumber($form_data['title'], $form_data['topics']);
                
                if(isset($ml_result['error'])) {
                    $form_data['ml_error'] = $ml_result['error'];
                } else {
                    // Store the ML-predicted class number
                    $form_data['ml_class'] = $ml_result['class_number'];
                    
                    // Generate the full call number - ML class + your Cutter
                    if(!empty($form_data['cutter'])) {
                        $form_data['full_call_number'] = $ml_result['class_number'] . ' ' . $form_data['cutter'];
                        
                        // Add publication year if available
                        if($form_data['publication_date'] != 'Not specified') {
                            $year = date('Y', strtotime($form_data['publication_date']));
                            $form_data['full_call_number'] .= ' ' . $year;
                        }
                    }
                }
            } else {
                $form_data['ml_error'] = 'Insufficient data for prediction. Title and topics are required.';
            }
            
            $success_message = "Form submitted successfully. Review the data in the modal.";
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Add New Book</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; position: relative; }
        h1 { color: #333; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; box-sizing: border-box; }
        .author-entry, .topic-entry { margin-bottom: 10px; padding: 10px; background: #f9f9f9; }
        button { padding: 10px 15px; background: #4CAF50; color: white; border: none; cursor: pointer; }
        .remove-btn { background: #f44336; }
        .success { color: green; background: #e8f5e9; padding: 10px; border-radius: 4px; margin-bottom: 10px; }
        .error { color: red; background: #ffebee; padding: 10px; border-radius: 4px; margin-bottom: 10px; }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .data-table th {
            background-color: #f2f2f2;
            text-align: left;
            padding: 8px;
        }
        
        .data-table td {
            border-top: 1px solid #ddd;
            padding: 8px;
        }
        
        .data-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .call-number {
            font-family: monospace;
            font-weight: bold;
            padding: 5px;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 3px;
            display: inline-block;
        }
        
        .cutter-number {
            font-family: monospace;
            font-weight: bold;
            padding: 5px;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 3px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <h1>Test Add New Book</h1>
    
    <?php if(!empty($success_message)): ?>
        <div class="success"><?php echo $success_message; ?></div>
    <?php endif; ?>
    
    <?php if(!empty($error_message)): ?>
        <div class="error"><?php echo $error_message; ?></div>
    <?php endif; ?>
    
    <form method="post" action="" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_book">
        
        <div class="form-group">
            <label for="title">Title:</label>
            <input type="text" id="title" name="title" required value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
        </div>
        
        <div id="authors-container">
            <h3>Authors</h3>
            <div class="author-entry">
                <div class="form-group">
                    <label>First Name:</label>
                    <input type="text" name="author_first_name[]" required>
                </div>
                <div class="form-group">
                    <label>Middle Name:</label>
                    <input type="text" name="author_middle_name[]">
                </div>
                <div class="form-group">
                    <label>Last Name:</label>
                    <input type="text" name="author_last_name[]" required>
                </div>
            </div>
        </div>
        
        <button type="button" id="add-author">Add Another Author</button>
        
        <div class="form-group">
            <label for="publication_date">Publication Date:</label>
            <input type="date" id="publication_date" name="publication_date" value="<?php echo isset($_POST['publication_date']) ? htmlspecialchars($_POST['publication_date']) : ''; ?>">
        </div>
        
        <div id="topics-container">
            <h3>Topics</h3>
            <div class="topic-entry">
                <div class="form-group">
                    <label>Topic Name:</label>
                    <input type="text" name="topic_name[]" required>
                    <input type="hidden" name="topic_order[]" value="1">
                </div>
            </div>
        </div>
        
        <button type="button" id="add-topic">Add Another Topic</button>
        <br>
        <button type="submit" style="margin-top: 20px;">Add Book</button>
    </form>

    <!-- Data Display Modal -->
    <div id="dataModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Book Information Preview</h2>
            
            <?php if(!empty($form_data)): ?>
                <table class="data-table">
                    <tr>
                        <th colspan="2">Book Details</th>
                    </tr>
                    <tr>
                        <td><strong>Title:</strong></td>
                        <td><?php echo htmlspecialchars($form_data['title']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Publication Date:</strong></td>
                        <td><?php echo htmlspecialchars($form_data['publication_date']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Authors:</strong></td>
                        <td>
                            <?php if(!empty($form_data['authors'])): ?>
                                <ul style="margin: 0; padding-left: 20px;">
                                    <?php foreach($form_data['authors'] as $author): ?>
                                        <li><?php echo htmlspecialchars($author); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                No authors specified
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Topics:</strong></td>
                        <td>
                            <?php if(!empty($form_data['topics'])): ?>
                                <ul style="margin: 0; padding-left: 20px;">
                                    <?php foreach($form_data['topics'] as $topic): ?>
                                        <li><?php echo htmlspecialchars($topic); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                No topics specified
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if(!empty($form_data['full_call_number'])): ?>
                    <tr>
                        <td><strong>Call Number:</strong></td>
                        <td><span class="call-number"><?php echo htmlspecialchars($form_data['full_call_number']); ?></span></td>
                    </tr>
                    <tr>
                    <td colspan="2" style="font-size: 0.9em; color: #555;">
                            <strong>Call Number Components:</strong><br>
                            - Classification: <span class="call-number"><?php echo htmlspecialchars($form_data['ml_class']); ?></span> (ML Model)<br>
                            - Cutter: <span class="cutter-number"><?php echo htmlspecialchars($form_data['cutter']); ?></span> 
                            <small>(Based on: <?php echo htmlspecialchars($form_data['cutter_source']); ?>)</small>
                            <?php if($form_data['publication_date'] != 'Not specified'): ?>
                                <br>- Year: <?php echo date('Y', strtotime($form_data['publication_date'])); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php elseif(!empty($form_data['ml_error'])): ?>
                    <tr>
                        <td><strong>ML Prediction:</strong></td>
                        <td class="error">
                            Error: <?php echo htmlspecialchars($form_data['ml_error']); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Cutter:</strong></td>
                        <td>
                            <span class="cutter-number"><?php echo htmlspecialchars($form_data['cutter']); ?></span>
                            <small>(Based on: <?php echo htmlspecialchars($form_data['cutter_source']); ?>)</small>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const modal = document.getElementById('dataModal');
            const span = document.getElementsByClassName('close')[0];
            
            // If we have form data, show modal automatically
            <?php if(!empty($form_data)): ?>
                modal.style.display = "block";
            <?php endif; ?>
            
            // When the user clicks on <span> (x), close the modal
            span.onclick = function() {
                modal.style.display = "none";
            }
            
            // When the user clicks anywhere outside of the modal, close it
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }
            
            // Add author functionality
            document.getElementById('add-author').addEventListener('click', function() {
                const authorEntry = document.createElement('div');
                authorEntry.className = 'author-entry';
                authorEntry.innerHTML = `
                    <div class="form-group">
                        <label>First Name:</label>
                        <input type="text" name="author_first_name[]" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name:</label>
                        <input type="text" name="author_middle_name[]">
                    </div>
                    <div class="form-group">
                        <label>Last Name:</label>
                        <input type="text" name="author_last_name[]" required>
                    </div>
                    <button type="button" class="remove-btn" onclick="this.parentNode.remove()">Remove</button>
                `;
                document.getElementById('authors-container').appendChild(authorEntry);
            });
            
            // Add topic functionality
            document.getElementById('add-topic').addEventListener('click', function() {
                const topicEntries = document.querySelectorAll('.topic-entry');
                const nextOrder = topicEntries.length + 1;
                
                const topicEntry = document.createElement('div');
                topicEntry.className = 'topic-entry';
                topicEntry.innerHTML = `
                    <div class="form-group">
                        <label>Topic Name:</label>
                        <input type="text" name="topic_name[]" required>
                        <input type="hidden" name="topic_order[]" value="${nextOrder}">
                    </div>
                    <button type="button" class="remove-btn" onclick="this.parentNode.remove(); updateTopicOrders()">Remove</button>
                `;
                document.getElementById('topics-container').appendChild(topicEntry);
            });
            
            // Function to update topic order numbers
            window.updateTopicOrders = function() {
                const topicEntries = document.querySelectorAll('.topic-entry');
                topicEntries.forEach((entry, index) => {
                    entry.querySelector('input[name="topic_order[]"]').value = index + 1;
                });
            };
        });
    </script>
</body>
</html>