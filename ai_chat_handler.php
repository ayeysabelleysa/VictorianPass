<?php
header('Content-Type: application/json');

// Simple AI Response Handler for VictorianPass
// In a production environment, you would integrate with a real AI API like OpenAI, Cohere, or Hugging Face

session_start();

$isResident = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident';

$response = [
    'success' => false,
    'message' => '',
    'response' => ''
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');

if (empty($query)) {
    $response['message'] = 'Query is required';
    echo json_encode($response);
    exit;
}

// Knowledge base of common questions and answers
$knowledgeBase = [
    // Registration Questions
    [
        'keywords' => ['register', 'registration', 'how do i register', 'sign up', 'create account'],
        'response' => 'To register as a resident in VictorianPass, click the "Register" button in the top navigation. Fill in your personal information including your full name, email, password, and house number. After submitting, you will receive a verification email. Click the link in the email to confirm your account. Once verified, you can log in and access all resident features.'
    ],
    // Visitor Pass Questions
    [
        'keywords' => ['visitor', 'visitor pass', 'request visitor', 'request a visitor pass', 'guest pass', 'entry pass'],
        'response' => 'To request a visitor pass, navigate to the "Reserve" section or click on your profile dropdown and select "Reserve an Amenity". You can submit visitor information including their name, identification type, and visit date. Your request will be reviewed by administration, and you\'ll receive updates on its status.'
    ],
    // Amenities Questions
    [
        'keywords' => ['amenities', 'amenity', 'facilities', 'what amenities', 'view amenities', 'what facilities'],
        'response' => '<strong>Victorian Heights Subdivision offers excellent amenities:</strong><br><ul><li>Clubhouse - Ideal for gatherings and community events</li><li>Multi-Purpose Building - A versatile space for various community activities</li><li>Basketball Court - For sports and fitness activities</li><li>Tennis Court - For recreational and competitive play</li></ul>All amenities can be reserved through the VictorianPass system. Check the "Amenities" section on our homepage for more details.'
    ],
    // QR System Questions
    [
        'keywords' => ['qr', 'qr system', 'how does qr work', 'qr code', 'qr scanning', 'how does the qr visitor system work'],
        'response' => 'Our QR system provides fast and secure access control for the subdivision. When you reserve an amenity or request a visitor pass, a unique QR code is generated. Simply present this QR code at the security gate or amenity entrance. The system scans the code for verification and grants access. This streamlines entry and improves security for our community.'
    ],
    // Contact Questions
    [
        'keywords' => ['contact', 'contact administration', 'contact admin', 'how do i contact', 'support', 'help', 'reach out'],
        'response' => '<strong>To contact the administration:</strong><br><ul><li><strong>Email:</strong> admin@victorianpass.com</li><li><strong>Phone:</strong> +63 (2) 1234-5678</li><li><strong>Office Hours:</strong> Monday - Friday, 9:00 AM - 5:00 PM</li><li>You can also submit an incident report through the "Report Incident" section in your dashboard.</li></ul>'
    ],
    // Status Checking
    [
        'keywords' => ['check status', 'status', 'my status', 'application status', 'where is my'],
        'response' => '<strong>To check the status of your visitor pass, amenity reservation, or incident report:</strong><br><ol><li>Log in to your VictorianPass account</li><li>Click on your profile icon in the top right</li><li>Select "Open Full View of Profile Dashboard"</li><li>Navigate to the appropriate section (Reservations, Visitor Passes, Reports) to view status updates</li></ol>Status updates are also sent via email notification.'
    ],
    // Security & Access
    [
        'keywords' => ['security', 'access', 'entry', 'gate', 'secure'],
        'response' => '<strong>Victorian Heights prioritizes security and community safety.</strong> Our QR-based access system ensures:<br><ul><li>Controlled entry through the main gate</li><li>Real-time tracking of visitor access</li><li>Secure amenity reservations</li><li>Enhanced incident reporting and response</li></ul>All residents and visitors must provide valid identification at the gate. Security personnel monitor access 24/7.'
    ],
    // Incident Reporting
    [
        'keywords' => ['report', 'incident', 'report incident', 'problem', 'issue', 'concern'],
        'response' => '<strong>To report an incident in Victorian Heights:</strong><br><ol><li>Log in to your VictorianPass account</li><li>Go to "Report Incident" from the main menu</li><li>Describe the incident with details (date, time, location)</li><li>Upload supporting photos or documents if applicable</li><li>Submit your report</li></ol>Administration will review and respond to your report. You can track the status from your dashboard.'
    ],
    // Profile & Account
    [
        'keywords' => ['profile', 'account', 'edit profile', 'update profile', 'change password', 'password'],
        'response' => '<strong>To manage your VictorianPass account:</strong><br><ul><li>Click your profile icon in the top navigation</li><li>Select "Open Full View of Profile Dashboard"</li><li>Update your personal information, email, or phone number</li><li>To change your password, use the "Change Password" option in your account settings</li><li>All changes require authentication for security purposes</li></ul>'
    ],
    // Reservation Questions
    [
        'keywords' => ['reserve', 'reservation', 'book', 'booking', 'reserve amenity'],
        'response' => '<strong>To reserve an amenity at Victorian Heights:</strong><br><ol><li>Log in to your account (residents) or submit a visitor pass request (guests)</li><li>Click "Reserve an Amenity"</li><li>Select the facility you wish to reserve</li><li>Choose your preferred date and time</li><li>Confirm your reservation</li></ol>You\'ll receive a confirmation email with your QR code for entry at the scheduled time.'
    ],
    // About the System
    [
        'keywords' => ['about', 'about the system', 'what is', 'tell me about', 'how does it work'],
        'response' => '<strong>VictorianPass is a modern subdivision management system designed to:</strong><br><ul><li>Streamline amenity reservations</li><li>Manage visitor access securely</li><li>Facilitate incident reporting</li><li>Provide real-time updates via QR technology</li></ul>Our system enhances community safety, improves convenience, and strengthens the sense of belonging in Victorian Heights Subdivision.'
    ],
    // General Information
    [
        'keywords' => ['victorian heights', 'subdivision', 'about us', 'information', 'location'],
        'response' => '<strong>Victorian Heights Subdivision</strong> is a gated residential community located in Brgy. Sauyo, Quezon City. Developed by Swire Land Corporation, it features:<br><ul><li>222 residential homes</li><li>Approximately 2,220 residents</li><li>Complete security and gate-controlled access</li><li>Modern amenities (clubhouse, multi-purpose building, sports courts)</li><li>Professional community management</li></ul>Our community emphasizes safety, convenience, and a strong sense of belonging.'
    ],
    // FAQ Category
    [
        'keywords' => ['faq', 'frequently asked', 'common questions', 'help'],
        'response' => '<strong>Popular questions about VictorianPass:</strong><br><ol><li><strong>How do I register?</strong> → Click Register and fill in your information</li><li><strong>How do I request a visitor pass?</strong> → Go to Reservations and submit visitor details</li><li><strong>What amenities are available?</strong> → Clubhouse, Multi-Purpose Building, Basketball Court, Tennis Court</li><li><strong>How does the QR system work?</strong> → Scan your QR code at entry points for secure access</li><li><strong>How do I contact administration?</strong> → Email: admin@victorianpass.com or call during office hours</li></ol>Need more help? Our support team is ready to assist.'
    ],
    // Smart Waste Segregation - How to Use Station
    [
        'keywords' => ['waste', 'segregation', 'how', 'smart waste station', 'segregation station', 'how to use'],
        'response' => "<strong>The Smart Waste Segregation Station is easy to use!</strong> Here's how it works:<br><br><strong>Step 1 — Get your QR code.</strong><br>Log in to your VictorianPass account on the website and download your personal QR code. Each household can register up to two (2) resident representatives, each with their own unique QR code.<br><br><strong>Step 2 — Scan at the station.</strong><br>Bring your recyclable materials to the Smart Waste Segregation Station located within Victorian Heights Subdivision. Hold your QR code up to the GM65 scanner on the station. The LCD screen will display your name and current points balance to confirm your session has started.<br><br><strong>Step 3 — Deposit your recyclables.</strong><br>The station accepts three types of recyclable materials:<br><ul><li>Plastics (PET bottles 1,000ml and below only — e.g., 500ml, 600ml, 1L bottles)</li><li>Aluminum Cans (small to medium soda and canned goods)</li><li>Paper & Cardboard (old documents, newspapers, and small to medium cardboard boxes)</li></ul>Place your materials into the correct bin — the system's sensors automatically detect the material type and measure the weight.<br><br><strong>Step 4 — Earn points automatically.</strong><br>Once deposited, points are instantly credited to your VictorianPass account based on the weight of what you recycled:<br><ul><li>Plastic earns 55 pts/kg</li><li>Aluminum Cans earn 140 pts/kg</li><li>Paper & Cardboard earns 30 pts/kg</li></ul>You can earn up to 100 points per day and 250 points per week per representative, with a maximum of 3 deposit sessions per day.<br><br><strong>Step 5 — Redeem your points.</strong><br>Log back into VictorianPass to check your points balance and redeem rewards.<br><br><strong>Note:</strong> The station does not accept wet or contaminated materials, items larger than the bin openings, or plastic bottles larger than 1,000ml. Make sure your recyclables are clean and dry before depositing!"
    ],
    // Smart Waste - Points Rates & Limits
    [
        'keywords' => ['points', 'how many points', 'points per kg', 'earn points', 'waste points', 'rates'],
        'response' => '<strong>Smart Waste Segregation Station — Points Rates:</strong><br><br>Earn points based on the weight of recyclable materials you deposit:<br><br><strong>♻️ Plastic (PET bottles 1,000ml and below):</strong> 55 points per kg<br><ul><li>Includes: 500ml, 600ml, 1L bottles</li><li>Tip: Rinse and flatten to save space</li></ul><strong>🥫 Aluminum Cans (small to medium):</strong> 140 points per kg<br><ul><li>Includes: Soda cans, canned goods containers</li><li>Tip: Most valuable! Flatten cans for easy transport</li></ul><strong>📄 Paper & Cardboard (clean and dry):</strong> 30 points per kg<br><ul><li>Includes: Old documents, newspapers, small to medium cardboard boxes</li><li>Tip: Remove plastic tape and keep dry</li></ul><strong>Daily & Weekly Limits:</strong><br><ul><li>Maximum 100 points per day per representative</li><li>Maximum 250 points per week per representative</li><li>Maximum 3 deposit sessions per day</li></ul>Contribution limits reset weekly. Track your progress in your dashboard!'
    ],
    // Amenity Points - Free Hour Requirements
    [
        'keywords' => ['free hour', 'points for amenity', 'free amenity', 'how many points', 'basketball', 'tennis', 'clubhouse', 'multi-purpose', 'one hour free'],
        'response' => '<strong>Free Amenity Hours — Points Required:</strong><br><br>Redeem your Smart Waste points for complete free hours at our amenities. No partial discounts available — points are redeemed for complete free hours only. Leftover points stay in your account for future use.<br><br><strong>🏀 Basketball Court:</strong> 300 points for one free hour<br><strong>🎾 Tennis Court:</strong> 300 points for one free hour<br><strong>🏛️ Clubhouse:</strong> 600 points for one free hour<br><strong>🏢 Multi-Purpose Building:</strong> 750 points for one free hour<br><br><strong>How to Redeem:</strong><br><ol><li>Check your total points balance in your VictorianPass dashboard</li><li>Go to Amenity Reservations</li><li>Select your desired amenity and time slot</li><li>Apply points to your booking for a free hour</li><li>Confirm your reservation</li></ol><strong>Reminder:</strong> You can earn up to 100 points per day through waste deposits, so a free Basketball or Tennis Court hour is achievable in about 3 days!'
    ],
    // Check Current Points Balance
    [
        'keywords' => ['my points', 'total points', 'current points', 'how many points do i have', 'points balance', 'check points'],
        'response' => "<strong>Check Your Points Balance:</strong><br><br>To view your total Smart Waste Segregation points right now:<br><br><ol><li>Log in to your VictorianPass account</li><li>Go to your Dashboard</li><li>Look for the 'Smart Waste' or 'My Points' section</li><li>Your current points balance will be displayed prominently</li><li>You can also see:<br><ul><li>Points earned this week</li><li>Points earned this month</li><li>Your deposit history with dates and amounts</li><li>Available amenity bookings with your points</li><li>Remaining daily/weekly point limits</li></ul></li></ol>Your points are updated instantly after each deposit at the Smart Waste Segregation Station. The LCD screen at the station also shows your current balance when you scan your QR code.<br><br><strong>Need help?</strong> Contact administration at admin@victorianpass.com or call +63 (2) 1234-5678."
    ],
    // Smart Waste - How to Get Started
    [
        'keywords' => ['get started', 'how to start', 'begin', 'join program', 'register qr', 'first time'],
        'response' => '<strong>Get Started with Smart Waste Segregation!</strong><br><br><strong>Quick Start Guide:</strong><br><br><strong>1. Download Your QR Code:</strong><br><ul><li>Log in to VictorianPass</li><li>Go to Smart Waste program section</li><li>Download your personal QR code</li><li>Each household can have up to 2 representatives</li></ul><strong>2. Prepare Your Materials:</strong><br><ul><li>Collect PET plastic bottles (1,000ml and below only)</li><li>Gather aluminum cans (clean and dry)</li><li>Save paper and cardboard (clean and dry)</li><li>No wet or contaminated items accepted</li></ul><strong>3. Visit the Station:</strong><br><ul><li>Located within Victorian Heights Subdivision</li><li>Bring your QR code</li><li>Bring your sorted recyclables</li><li>Any day/time the station is open</li></ul><strong>4. Deposit and Earn:</strong><br><ul><li>Scan your QR code at the GM65 scanner</li><li>See your name and points balance on LCD screen</li><li>Place materials in correct bins</li><li>Points credit instantly</li></ul><strong>5. Track & Redeem:</strong><br><ul><li>Monitor points in your dashboard</li><li>Redeem for free amenity hours</li><li>Leftover points carry forward</li></ul>Earn up to 100 points daily! Start today!'
    ],
    // Smart Waste - Environmental Impact
    [
        'keywords' => ['environmental impact', 'help environment', 'sustainability', 'eco-friendly', 'save planet', 'green', 'environmental benefit'],
        'response' => "<strong>Your Waste Segregation Impact on Victorian Heights!</strong><br><br>Each contribution makes a real difference:<br><br><strong>Community Impact:</strong><br><ul><li>Reduce landfill waste significantly</li><li>Lower greenhouse gas emissions</li><li>Conserve natural resources</li><li>Support sustainable local initiatives</li><li>Build community environmental awareness</li></ul><strong>Your Reward:</strong><br><ul><li>Earn valuable points for every deposit</li><li>Get free amenity hours</li><li>Be recognized as an eco-conscious resident</li><li>Help achieve our community sustainability goals</li></ul>By participating in Smart Waste Segregation, you're not just earning points for free amenity time — you're actively contributing to a greener Victorian Heights Subdivision!<br><br>Every kg of plastic, aluminum, and paper you deposit helps us move toward zero waste goals."
    ],
];

function isResidentOnlyAIQuery($query) {
    $residentOnlyKeywords = [
        'smart waste',
        'waste segregation',
        'segregation station',
        'my points',
        'points balance',
        'current points',
        'total points',
        'earn points',
        'redeem points',
        'reward',
        'rewards',
        'free hour',
        'amenity points',
        'recycling activities'
    ];

    $queryLower = strtolower($query);
    foreach ($residentOnlyKeywords as $keyword) {
        if (strpos($queryLower, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

// Function to find the best matching response
function findResponse($query, $knowledgeBase) {
    $queryLower = strtolower($query);
    $bestMatch = null;
    $bestScore = 0;

    foreach ($knowledgeBase as $item) {
        $score = 0;
        foreach ($item['keywords'] as $keyword) {
            if (stripos($queryLower, $keyword) !== false) {
                $score += 1;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $item;
        }
    }

    if ($bestMatch) {
        return $bestMatch['response'];
    }

    // Default response if no match found
    return 'Thank you for your question! Our VictorianPass system is designed to help you with amenity reservations, visitor access management, and incident reporting. For specific concerns, please contact our administration team at admin@victorianpass.com or call +63 (2) 1234-5678.';
}

// Restrict resident-only perks from visitor and public sessions
if (!$isResident && isResidentOnlyAIQuery($query)) {
    $aiResponse = 'Smart Waste Station points, rewards, and booking perks are available only to resident users. Please log in with a resident account to view points balances, rewards, and redemption options.';
} else {
    $aiResponse = findResponse($query, $knowledgeBase);
}

$response['success'] = true;
$response['response'] = $aiResponse;

echo json_encode($response);
?>
