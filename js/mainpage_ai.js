// VictorianPass AI Chatbot Logic
document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const aiChatToggle = document.getElementById('aiChatToggle');
    const aiChatbotPanel = document.getElementById('aiChatbotPanel');
    const chatbotMinimize = document.getElementById('chatbotMinimize');
    const chatbotClose = document.getElementById('chatbotClose');
    const chatbotInput = document.getElementById('chatbotInput');
    const chatbotSendBtn = document.getElementById('chatbotSendBtn');
    const chatbotMessages = document.getElementById('chatbotMessages');
    const chatbotClear = document.getElementById('chatbotClear');
    const chatbotWelcome = document.getElementById('chatbotWelcome');
    const chatbotTyping = document.getElementById('chatbotTyping');
    const aiSearchInput = document.getElementById('aiSearchInput');
    const suggestedBtns = document.querySelectorAll('.suggested-btn');

    let isMinimized = false;

    // Toggle Chatbot Panel
    aiChatToggle.addEventListener('click', function(e) {
        e.preventDefault();
        aiChatbotPanel.classList.toggle('active');
        if (isMinimized) {
            aiChatbotPanel.classList.remove('minimized');
            isMinimized = false;
        }
        if (aiChatbotPanel.classList.contains('active')) {
            chatbotInput.focus();
        }
    });

    // Minimize Chatbot
    chatbotMinimize.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        aiChatbotPanel.classList.toggle('minimized');
        isMinimized = !isMinimized;
    });

    // Close Chatbot
    chatbotClose.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        aiChatbotPanel.classList.remove('active', 'minimized');
        isMinimized = false;
    });

    // Send Message on Button Click
    chatbotSendBtn.addEventListener('click', sendMessage);

    // Send Message on Enter Key
    chatbotInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // AI Search Bar Input - Open Chatbot and Send Query
    aiSearchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = aiSearchInput.value.trim();
            if (query) {
                // Open chatbot
                aiChatbotPanel.classList.add('active');
                if (isMinimized) {
                    aiChatbotPanel.classList.remove('minimized');
                    isMinimized = false;
                }
                // Send the search query
                sendAIQuery(query);
                // Clear search bar
                aiSearchInput.value = '';
                chatbotInput.focus();
            }
        }
    });

    // Suggested Questions Click
    suggestedBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const question = this.dataset.question;
            // Open chatbot
            aiChatbotPanel.classList.add('active');
            if (isMinimized) {
                aiChatbotPanel.classList.remove('minimized');
                isMinimized = false;
            }
            // Send the suggested question
            sendAIQuery(question);
            chatbotInput.focus();
        });
    });

    // Clear Chat
    chatbotClear.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        chatbotMessages.innerHTML = '';
        chatbotWelcome.style.display = 'block';
        chatbotInput.value = '';
    });

    // Send Message Function
    function sendMessage() {
        const message = chatbotInput.value.trim();
        if (message) {
            sendAIQuery(message);
            chatbotInput.value = '';
        }
    }

    // Send Query to AI
    function sendAIQuery(query) {
        // Hide welcome message when first message is sent
        if (chatbotWelcome.style.display !== 'none') {
            chatbotWelcome.style.display = 'none';
        }

        // Add user message to chat
        addMessage(query, 'user');

        // Show typing indicator
        chatbotTyping.style.display = 'flex';
        scrollToBottom();

        // Send to backend
        fetch('ai_chat_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query: query })
        })
        .then(response => response.json())
        .then(data => {
            chatbotTyping.style.display = 'none';
            
            if (data.success) {
                // Simulate slight delay for more natural feel
                setTimeout(() => {
                    addMessage(data.response, 'ai');
                    scrollToBottom();
                }, 300);
            } else {
                addMessage('Sorry, I encountered an error processing your request. Please try again.', 'ai');
                scrollToBottom();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            chatbotTyping.style.display = 'none';
            addMessage('Sorry, I\'m having trouble connecting. Please check your internet and try again.', 'ai');
            scrollToBottom();
        });
    }

    // Add Message to Chat
    function addMessage(text, sender) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `chatbot-message message-${sender}`;

        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.textContent = sender === 'user' ? 'You' : 'AI';

        const content = document.createElement('div');
        content.className = 'message-content';
        
        // Handle line breaks in response
        const paragraphs = text.split('\n');
        paragraphs.forEach((para, index) => {
            if (para.trim()) {
                const p = document.createElement('div');
                p.textContent = para;
                content.appendChild(p);
            }
        });

        if (sender === 'user') {
            messageDiv.appendChild(content);
            messageDiv.appendChild(avatar);
        } else {
            messageDiv.appendChild(avatar);
            messageDiv.appendChild(content);
        }

        chatbotMessages.appendChild(messageDiv);
    }

    // Auto-scroll to bottom
    function scrollToBottom() {
        setTimeout(() => {
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }, 10);
    }

    // Close chatbot when clicking outside (on mobile)
    document.addEventListener('click', function(e) {
        const isClickInsideChatbot = aiChatbotPanel.contains(e.target);
        const isClickOnToggle = aiChatToggle.contains(e.target);
        
        if (!isClickInsideChatbot && !isClickOnToggle && aiChatbotPanel.classList.contains('active')) {
            // Don't close on mobile if it's just a touch - keep it open
            // This prevents accidental closures during typing
        }
    });

    // Prevent chatbot input blur on mobile
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        chatbotInput.addEventListener('blur', function() {
            setTimeout(() => {
                if (aiChatbotPanel.classList.contains('active')) {
                    this.focus();
                }
            }, 100);
        });
    }

    // Keyboard shortcuts (optional)
    document.addEventListener('keydown', function(e) {
        // Ctrl+K or Cmd+K to open AI assistant
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            aiChatToggle.click();
        }
    });
});
