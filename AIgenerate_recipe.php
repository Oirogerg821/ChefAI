<?php
session_start();
include 'db_connect.php';

// Function to save to database history
function saveToHistory($user_id, $message, $response = null) {
    global $conn;
    
    $stmt = $conn->prepare("INSERT INTO chat_history (user_id, message, response) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $message, $response);
    $stmt->execute();
    $stmt->close();
    
    // Keep only last 10 items per user
    $conn->query("DELETE FROM chat_history WHERE user_id = $user_id AND id NOT IN (
        SELECT id FROM (
            SELECT id FROM chat_history WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 10
        ) AS temp
    )");
}

// Function to get user history
function getUserHistory($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT id, message, response, is_bookmarked FROM chat_history WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
   //hey
    $stmt->close();
    return $history;
}

// Function to get specific history item
function getHistoryItem($user_id, $history_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT message, response FROM chat_history WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $history_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    return $item;
}

// Handle delete history item
if (isset($_POST['delete_history'])) {
    $history_id = $_POST['delete_history'];
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("DELETE FROM chat_history WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $history_id, $user_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Handle bookmark history item
if (isset($_POST['bookmark_history'])) {
    $history_id = $_POST['bookmark_history'];
    $user_id = $_SESSION['user_id'];
    
    // Toggle bookmark status
    $stmt = $conn->prepare("UPDATE chat_history SET is_bookmarked = NOT is_bookmarked WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $history_id, $user_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Handle view history item
$viewingHistory = false;
if (isset($_GET['view_history'])) {
    $history_id = $_GET['view_history'];
    $user_id = $_SESSION['user_id'];
    $historyItem = getHistoryItem($user_id, $history_id);
    
    if ($historyItem) {
        $viewingHistory = true;
        $user_input = $historyItem['message'];
        $responseText = $historyItem['response'];
    }
}

// Get current user's history
$userHistory = [];
if (isset($_SESSION['user_id'])) {
    $userHistory = getUserHistory($_SESSION['user_id']);
}

$recipe = isset($_GET['recipe']) ? htmlspecialchars($_GET['recipe']) : '';
?>
<?php include 'side-nav.php'?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kain na! - Filipino Recipe Generator</title>
    <link rel="stylesheet" href="AIgenerate.css">
    <style>
        .suggestion-box {
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .suggestion-box:hover {
            background-color: #f0f0f0;
        }
        .error-message {
            color: red;
            font-weight: bold;
        }
        .user-prompt {
            background-color: #f0f8ff;
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #4682b4;
        }
        .ai-response-container {
            margin-top: 20px;
        }
        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            margin-bottom: 5px;
            border-radius: 4px;
            background-color: #f8f9fa;
            cursor: pointer;
        }
        
        .history-actions {
            display: flex;
            gap: 5px;
        }
        
        .bookmark-history-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: #ccc;
            padding: 0 5px;
        }
        
        .bookmark-history-btn.bookmarked {
            color: #ffd700;
        }
        
        .bookmark-history-btn:not(.bookmarked):hover {
            color: #ffd700;
        }
        
        .delete-history-btn {
            background-color: #ff6b6b;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 2px 6px;
            cursor: pointer;
            font-size: 12px;
        }
    </style>
</head>
<body>
   
    <div class="main-container">
        <div class="recipe-container">
            <div class="header">
                <h1>Chef-AI! <span class="subtitle">A Filipino Recipe Generator</span></h1>
                <p class="tagline">Let's Try Filipino Dishes together!</p>
                <p class="powered-by">Explore Filipino Chef-AI</p>
            </div>
            
            <div class="suggestions">
                <div class="suggestion-box" onclick="setSuggestion(this)">What can I cook with chicken and rice?</div>
                <div class="suggestion-box" onclick="setSuggestion(this)">I have eggplant. What Filipino dish can I make?</div>
                <div class="suggestion-box" onclick="setSuggestion(this)">Suggested Filipino dessert for summer.</div>
                <div class="suggestion-box" onclick="setSuggestion(this)">Part of a week of vegetarian Filipino snacks.</div>
            </div>
            
            <form method="post" id="recipe-form">
                <input type="text" name="user_input" id="recipe-input" placeholder="Ask about Filipino recipes..." required>
                <button type="submit">Generate Recipe</button>
            </form>
            
            <div class="ai-response-container">
                <div class="ai-response-header">Recipe Details</div>
                <div class="ai-response">
                    <?php
                    if (!empty($recipe) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
                        $defaultPrompt = "For Philippine food Only. Give me a detailed recipe for $recipe including ingredients and step-by-step instructions with preparation time. Use ingredients commonly available in the Philippine. Include cultural context about this dish.";
                        echo "<script>
                            document.getElementById('recipe-input').value = `".addslashes($defaultPrompt)."`; 
                            document.querySelector('form').submit();
                        </script>";
                        echo '<div class="loading">Loading recipe for '.htmlspecialchars($recipe).'...</div>';
                    }

                    if (isset($viewingHistory) && $viewingHistory) {
                        echo '<div class="user-prompt">'.htmlspecialchars($user_input).'</div>';
                        
                        // Apply formatting
                        $formattedResponse = preg_replace('/\[Chef-AI Response\]/', '<div class="ai-response-title">Chef-AI Response</div>', $responseText);
                        $formattedResponse = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $formattedResponse);
                        $formattedResponse = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $formattedResponse);
                        $formattedResponse = preg_replace('/\n+/', '<br>', $formattedResponse);
                        $formattedResponse = preg_replace('/(Ingredients:)/', '<h3>$1</h3>', $formattedResponse);
                        $formattedResponse = preg_replace('/(Instructions:)/', '<h3>$1</h3>', $formattedResponse);
                        $formattedResponse = preg_replace('/(Preparation Time:)/', '<h4>$1</h4>', $formattedResponse);
                        $formattedResponse = preg_replace('/(Cultural Context:)/', '<h3>$1</h3>', $formattedResponse);
                        $formattedResponse = preg_replace('/(Substitutions:)/', '<h3>$1</h3>', $formattedResponse);
                        $formattedResponse = strip_tags($formattedResponse, '<br><strong><em><h3><h4><p><ul><li><ol><div>');

                        echo '<div id="ai-response-text" style="font-family:monospace; white-space: pre-wrap;">';
                        echo $formattedResponse;
                        echo '</div>';
                    }
                    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['user_input'])) {
                        $user_input = $_POST['user_input'];
                        
                        // Display user prompt in the response container
                        echo '<div class="user-prompt">'.htmlspecialchars($user_input).'</div>';
                        
                        // This section is to instruct ai what to do
                        $system_instruction = "You are a Filipino cuisine expert assistant specialized in helping people cook Filipino food with philippine ingredients. 
                        Your responses must:
                        1. can you start with calling them latino/latina,
                        2. Suggest ingredient substitutions when needed
                        3. Include cultural context about dishes
                        4. Provide brand recommendations (like Mama Sita, Datu Puti, etc.) when relevant
                        5. Offer dietary alternatives (vegan, gluten-free, etc.)
                        6. If someone ask you what is you're ai name. it's Chef-AI
                        7. you can get an idea in this link https://www.seriouseats.com/how-to-get-started-cooking-filipino-food-5270830
                        8. Only provide information about Filipino food and cooking

                        Format your response like this:
                        [Chef-AI Response]
                        [Your response here]
                        
                        If asked about non-Filipino food, politely decline and suggest a Filipino alternative."; 
                        $api_key = 'AIzaSyDKpqjVwiztSZ8up3Sq8DziM1DA1ge3Bjg'; //This is the AI-API key 

                        if (empty($api_key)) {
                            echo '<p class="error-message">API key is missing. Please configure the Chef-AI system.</p>';
                        } else {
                            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $api_key;

                            $data = [
                                'contents' => [
                                    [
                                        'parts' => [
                                            ['text' => $system_instruction]
                                        ],
                                        'role' => 'model'
                                    ],
                                    [
                                        'parts' => [
                                            ['text' => 'I understand. I will only provide information about Filipino cuisine and cooking, with a focus on ingredients available in the US.']
                                        ],
                                        'role' => 'user'
                                    ],
                                    [
                                        'parts' => [
                                            ['text' => $user_input]
                                        ],
                                        'role' => 'user'
                                    ]
                                ]
                            ];

                            $headers = [
                                'Content-Type: application/json',
                            ];

                            $options = [
                                'http' => [
                                    'header' => implode("\r\n", $headers),
                                    'method' => 'POST',
                                    'content' => json_encode($data),
                                    'ignore_errors' => true
                                ]
                            ];

                            try {
                                $context = stream_context_create($options);
                                $response = @file_get_contents($url, false, $context);
                                
                                if ($response === FALSE) {
                                    $error = error_get_last();
                                    $errorMessage = $error['message'] ?? 'Unknown error occurred';
                                    
                                    if (function_exists('curl_init')) {
                                        $ch = curl_init();
                                        curl_setopt($ch, CURLOPT_URL, $url);
                                        curl_setopt($ch, CURLOPT_POST, true);
                                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                        $response = curl_exec($ch);
                                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                        
                                        if (curl_errno($ch)) {
                                            $errorMessage = 'CURL Error: ' . curl_error($ch);
                                        } elseif ($httpCode >= 400) {
                                            $errorData = json_decode($response, true);
                                            $errorMessage = 'API Error: ' . ($errorData['error']['message'] ?? "HTTP $httpCode");
                                        }
                                        curl_close($ch);
                                    }
                                    
                                    echo '<p class="error-message">Error connecting to Chef-AI: ' . htmlspecialchars($errorMessage) . '</p>';
                                } else {
                                    $responseData = json_decode($response, true);
                                    
                                    if (isset($responseData['error'])) {
                                        echo '<p class="error-message">Chef-AI Error: ' . 
                                             htmlspecialchars($responseData['error']['message']) . '</p>';
                                    } elseif (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                                        $responseText = $responseData['candidates'][0]['content']['parts'][0]['text'];

                                        // Save to database history
                                        if (isset($_SESSION['user_id'])) {
                                            saveToHistory($_SESSION['user_id'], $user_input, $responseText);
                                            // Refresh history display
                                            $userHistory = getUserHistory($_SESSION['user_id']);
                                        }

                                        // Apply formatting
                                        $formattedResponse = preg_replace('/\[Chef-AI Response\]/', '<div class="ai-response-title">Chef-AI Response</div>', $responseText);
                                        $formattedResponse = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $formattedResponse);
                                        $formattedResponse = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $formattedResponse);
                                        $formattedResponse = preg_replace('/\n+/', '<br>', $formattedResponse);
                                        $formattedResponse = preg_replace('/(Ingredients:)/', '<h3>$1</h3>', $formattedResponse);
                                        $formattedResponse = preg_replace('/(Instructions:)/', '<h3>$1</h3>', $formattedResponse);
                                        $formattedResponse = preg_replace('/(Preparation Time:)/', '<h4>$1</h4>', $formattedResponse);
                                        $formattedResponse = preg_replace('/(Cultural Context:)/', '<h3>$1</h3>', $formattedResponse);
                                        $formattedResponse = preg_replace('/(Substitutions:)/', '<h3>$1</h3>', $formattedResponse);

                                        // Clean extra tags (security measure)
                                        $formattedResponse = strip_tags($formattedResponse, '<br><strong><em><h3><h4><p><ul><li><ol><div>');

                                        // Output with typewriter effect
                                        echo '<div id="ai-response-text" style="font-family:monospace; white-space: pre-wrap;"></div>';
                                        echo "<script>
                                            let responseText = `" . addslashes($formattedResponse) . "`;
                                            let index = 0;
                                            const typingSpeed = 10;
                                            
                                            function typeWriter() {
                                                if (index < responseText.length) {
                                                    document.getElementById('ai-response-text').innerHTML = 
                                                        responseText.substring(0, index + 1);
                                                    index++;
                                                    setTimeout(typeWriter, typingSpeed);
                                                    
                                                    // Auto-scroll to bottom
                                                    const element = document.getElementById('ai-response-text');
                                                    element.scrollTop = element.scrollHeight;
                                                }
                                            }
                                            typeWriter();
                                        </script>";
                                    } else {
                                        echo '<p class="error-message">Unexpected response format from Chef-AI. Response: ' . 
                                             htmlspecialchars(substr($response, 0, 200)) . '...</p>';
                                    }
                                }
                            } catch (Exception $e) {
                                echo '<p class="error-message">Exception: ' . htmlspecialchars($e->getMessage()) . '</p>';
                            }
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        
        <div class="history-container">
            <h3><i class="bi bi-clock-history me-2"></i>Recent Search</h3>
            <div class="history-list">
                <?php
                if (!empty($userHistory)) {
                    foreach ($userHistory as $item) {
                        echo '<div class="history-item">
                            <span onclick="viewHistoryItem(' . $item['id'] . ')">' . htmlspecialchars($item['message']) . '</span>
                            <div class="history-actions">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="bookmark_history" value="' . $item['id'] . '">
                                    <button type="submit" class="bookmark-history-btn ' . ($item['is_bookmarked'] ? 'bookmarked' : '') . '" 
                                            title="' . ($item['is_bookmarked'] ? 'Unbookmark' : 'Bookmark') . '">
                                        ' . ($item['is_bookmarked'] ? '★' : '☆') . '
                                    </button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="delete_history" value="' . $item['id'] . '">
                                    <button type="submit" class="delete-history-btn">×</button>
                                </form>
                            </div>
                        </div>';
                    }
                } else {
                    echo '<div class="text-muted">No history yet. Your searches will appear here.</div>';
                }
                ?>
            </div>
        </div>
    </div>
            </div>
        </div>
    </div>

    <script>
        // Function to set suggestion text in input field and submit form
        function setSuggestion(element) {
            document.getElementById('recipe-input').value = element.textContent;
            document.getElementById('recipe-form').submit();
        }
        
        // Function to view history item
        function viewHistoryItem(historyId) {
            window.location.href = '?view_history=' + historyId;
        }
        
        // Scroll to the response container after form submission
        document.getElementById('recipe-form').addEventListener('submit', function() {
            setTimeout(function() {
                document.querySelector('.ai-response-container').scrollIntoView({
                    behavior: 'smooth'
                });
            }, 500);
        });
        
        // Scroll to response if viewing history
        <?php if (isset($viewingHistory) && $viewingHistory): ?>
            document.querySelector('.ai-response-container').scrollIntoView({
                behavior: 'smooth'
            });
        <?php endif; ?>
    </script>
</body>
</html>