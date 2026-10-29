/**
 * BRLove API - Realtime Voice & WebSocket Client Controller
 * Módulo unificado para comunicação de áudio em tempo real (STT + TTS + WebAudio + VAD)
 * Elimina duplicações de código entre o Painel Admin e Área do Cliente.
 */

(function(window) {
    'use strict';

    class RealtimeConsole {
        constructor(config = {}) {
            this.wsUrl = config.wsUrl || '';
            this.apiKey = config.apiKey || '';
            
            // Estado interno
            this.ws = null;
            this.isConnected = false;
            this.isRecording = false;
            this.isLiveCallActive = false;
            this.mediaRecorder = null;
            this.audioChunks = [];
            this.eventCounter = 0;
            this.totalTokens = 0;
            this.totalMessages = 0;
            this.currentStreamingEl = null;
            this.audioSentenceQueue = [];
            this.isPlayingAudioSentence = false;
            this.currentAudioPlayer = null;
            this.recordingStartTime = 0;
            this.recordingTimerInterval = null;
            this.speechRecognizer = null;
            this.recognizedText = '';
            this.vadSilenceTimer = null;
            this.isAudioSpeaking = false;
            this.lastAudioSoundTime = 0;
            this.lastRenderedUserMsg = { text: '', time: 0 };
            this.isResponseStreamingActive = false;
            this.requestStartTime = 0;
            this.activeMicStream = null;

            this.initSpeechRecognition();
        }

        initSpeechRecognition() {
            try {
                const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
                if (SpeechRec) {
                    this.speechRecognizer = new SpeechRec();
                    this.speechRecognizer.continuous = true;
                    this.speechRecognizer.interimResults = true;
                    this.speechRecognizer.lang = 'pt-BR';

                    this.speechRecognizer.onresult = (event) => {
                        let full = '';
                        for (let i = 0; i < event.results.length; ++i) {
                            full += event.results[i][0].transcript + ' ';
                        }
                        this.recognizedText = full.trim();
                        const textInput = document.getElementById('textInput');
                        if (textInput && this.isRecording) {
                            textInput.placeholder = '🎙️ "' + this.recognizedText + '"';
                        }
                        this.triggerVadSilenceCheck();
                    };

                    this.speechRecognizer.onerror = (e) => {
                        console.warn('[SpeechRec Warn]', e.error);
                    };
                }
            } catch (e) {
                console.warn('SpeechRecognition não suportado neste navegador:', e);
            }
        }

        setVoiceMode(mode) {
            const btnTurbo = document.getElementById('btnModeTurbo');
            const btnHD = document.getElementById('btnModeHD');
            const selVoice = document.getElementById('cfgVoice');

            if (selVoice) selVoice.value = mode;

            if (mode === 'browser_fast') {
                if (btnTurbo) btnTurbo.className = 'px-2.5 py-1 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-cyan-500 text-gray-950 shadow';
                if (btnHD) btnHD.className = 'px-2.5 py-1 rounded-lg text-xs font-medium text-gray-400 hover:text-white transition-all flex items-center gap-1.5';
            } else {
                if (btnHD) btnHD.className = 'px-2.5 py-1 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-purple-600 text-white shadow';
                if (btnTurbo) btnTurbo.className = 'px-2.5 py-1 rounded-lg text-xs font-medium text-gray-400 hover:text-white transition-all flex items-center gap-1.5';
            }

            this.updateSession();
        }

        onVoiceSelectChange() {
            const selVoice = document.getElementById('cfgVoice');
            if (selVoice) {
                this.setVoiceMode(selVoice.value === 'browser_fast' ? 'browser_fast' : selVoice.value);
            }
        }

        triggerVadSilenceCheck() {
            if (this.vadSilenceTimer) clearTimeout(this.vadSilenceTimer);
            if (!this.isRecording) return;

            const handsFree = document.getElementById('cfgHandsFree')?.checked ?? true;
            if (!handsFree) return;

            if (this.recognizedText.trim().length > 1) {
                this.vadSilenceTimer = setTimeout(() => {
                    if (this.isRecording && this.recognizedText.trim().length > 1) {
                        this.finishSpeakingAndSend();
                    }
                }, 600);
            }
        }

        toggleConnection() {
            if (this.isConnected) {
                this.disconnect();
            } else {
                this.connect();
            }
        }

        connect() {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) return;

            this.setStatus('connecting');
            const url = `${this.wsUrl}?api_key=${encodeURIComponent(this.apiKey)}`;

            try {
                this.ws = new WebSocket(url);
            } catch (err) {
                this.setStatus('error');
                return;
            }

            this.ws.onopen = () => {
                this.logEvent('ws.open', { url: this.wsUrl }, 'client');
            };

            this.ws.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    this.handleServerEvent(data);
                } catch (err) {
                    console.error('Erro ao processar mensagem do WebSocket:', err);
                }
            };

            this.ws.onclose = (e) => {
                this.isConnected = false;
                this.setStatus('disconnected');
                this.setControlsEnabled(false);
                this.logEvent('ws.close', { code: e.code, reason: e.reason }, 'server');
            };

            this.ws.onerror = () => {
                this.setStatus('error');
                this.logEvent('ws.error', { message: 'Erro na conexão WebSocket' }, 'server');
            };
        }

        disconnect() {
            this.endLiveConversation();
            if (this.ws) {
                this.ws.close();
                this.ws = null;
            }
            this.isConnected = false;
            this.setStatus('disconnected');
            this.setControlsEnabled(false);
        }

        sendWsEvent(type, payload = {}) {
            if (!this.ws || this.ws.readyState !== WebSocket.OPEN) return;
            const event = { type, ...payload };
            this.ws.send(JSON.stringify(event));
            this.logEvent(type, payload, 'client');
        }

        updateSession() {
            if (!this.isConnected) return;
            const wsCheck = document.getElementById('cfgWebSearch');
            const selVoice = document.getElementById('cfgVoice');
            const modelEl = document.getElementById('cfgModel');
            const tempEl = document.getElementById('cfgTemp');
            const instEl = document.getElementById('cfgInstructions');

            this.sendWsEvent('session.update', {
                session: {
                    model: modelEl ? modelEl.value : 'claude-3-5-sonnet',
                    voice: selVoice ? selVoice.value : 'browser_fast',
                    temperature: parseFloat(tempEl?.value || '0.7'),
                    instructions: instEl?.value || '',
                    web_search: wsCheck ? wsCheck.checked : true
                }
            });
        }

        handleServerEvent(data) {
            const type = data.type;
            this.logEvent(type, data, 'server');

            switch (type) {
                case 'session.created':
                    this.isConnected = true;
                    this.setStatus('connected');
                    this.setControlsEnabled(true);
                    this.updateSession();
                    this.addSystemMessage('✨ Sessão Realtime conectada com sucesso!', 'info');
                    break;

                case 'input_audio_buffer.speech_started':
                    this.addSystemMessage('🎤 Processando fala...', 'processing');
                    break;

                case 'response.web_search.started':
                    this.addSystemMessage('🌐 Pesquisando ao vivo na Web: "' + (data.query || '') + '"...', 'websearch');
                    break;

                case 'response.web_search.completed':
                    this.removeSystemMessage('websearch');
                    if (data.count > 0) {
                        this.addSystemMessage('✅ ' + data.count + ' fontes consultadas em tempo real.', 'websearch-done');
                        setTimeout(() => this.removeSystemMessage('websearch-done'), 3500);
                    }
                    break;

                case 'conversation.item.created':
                    if (data.item?.role === 'user') {
                        const text = data.item.content?.[0]?.transcript || data.item.content?.[0]?.text || '';
                        if (text) {
                            this.removeSystemMessage('processing');
                            this.addChatMessage('user', text);
                            this.totalMessages++;
                            this.updateUsage();
                        }
                    }
                    break;

                case 'response.created':
                    this.isResponseStreamingActive = true;
                    this.currentStreamingEl = this.addChatMessage('assistant', '', true);
                    break;

                case 'response.text.delta':
                    if (this.currentStreamingEl && data.delta) {
                        this.appendToStreamingMessage(this.currentStreamingEl, data.delta);
                    }
                    break;

                case 'response.text.done':
                    if (this.currentStreamingEl) {
                        this.finalizeStreamingMessage(this.currentStreamingEl);
                        this.currentStreamingEl = null;
                    }
                    break;

                case 'response.audio.delta':
                    if (data.delta || data.transcript) {
                        this.enqueueSentenceAudio(data.delta, data.transcript);
                    }
                    break;

                case 'response.audio.done':
                    break;

                case 'response.cancelled':
                    if (this.currentAudioPlayer) {
                        try { this.currentAudioPlayer.pause(); this.currentAudioPlayer.currentTime = 0; } catch (e) {}
                        this.currentAudioPlayer = null;
                    }
                    if (window.speechSynthesis && window.speechSynthesis.speaking) {
                        try { window.speechSynthesis.cancel(); } catch (e) {}
                    }
                    this.audioSentenceQueue = [];
                    this.isPlayingAudioSentence = false;
                    this.isResponseStreamingActive = false;
                    break;

                case 'response.done':
                    this.isResponseStreamingActive = false;
                    const latency = data.response?.latency_ms || (this.requestStartTime > 0 ? (Date.now() - this.requestStartTime) : 0);
                    if (latency > 0) {
                        this.updateLatencyDisplay(latency);
                        this.tagLastAssistantMessageLatency(latency);
                    }
                    if (data.response?.usage) {
                        this.totalTokens += data.response.usage.total_tokens || 0;
                        this.totalMessages++;
                        this.updateUsage();
                    }
                    if (!this.isPlayingAudioSentence) {
                        this.checkAutoContinueSpeech();
                    }
                    break;

                case 'error':
                    const errMsg = data.message || data.error?.message || 'Erro desconhecido';
                    this.addSystemMessage('❌ ' + errMsg, 'error');
                    break;
            }
        }

        updateLatencyDisplay(latencyMs) {
            const badge = document.getElementById('badgeLatency');
            const val = document.getElementById('valLatency');
            if (badge && val) {
                badge.classList.remove('hidden');
                val.textContent = `${latencyMs} ms`;
                if (latencyMs < 1000) {
                    val.className = 'text-emerald-400 font-mono font-bold';
                } else if (latencyMs < 2000) {
                    val.className = 'text-cyan-400 font-mono font-bold';
                } else {
                    val.className = 'text-amber-400 font-mono font-bold';
                }
            }
        }

        tagLastAssistantMessageLatency(latencyMs) {
            const container = document.getElementById('chatMessages');
            if (!container) return;
            const assistantMsgs = container.querySelectorAll('[data-role="assistant"]');
            if (assistantMsgs.length > 0) {
                const lastEl = assistantMsgs[assistantMsgs.length - 1];
                const footerEl = lastEl.querySelector('.msg-footer');
                if (footerEl && !footerEl.querySelector('.latency-tag')) {
                    const tag = document.createElement('span');
                    tag.className = 'latency-tag text-[10px] px-2 py-0.5 rounded-md bg-gray-950/80 border border-emerald-500/30 text-emerald-400 font-mono font-bold flex items-center gap-1';
                    tag.innerHTML = `<i class="fa-solid fa-bolt text-amber-300"></i> ${latencyMs}ms`;
                    footerEl.appendChild(tag);
                }
            }
        }

        sendTextMessage() {
            const input = document.getElementById('textInput');
            if (!input) return;
            const text = input.value.trim();
            if (!text || !this.isConnected) return;

            input.value = '';
            this.requestStartTime = Date.now();

            this.sendWsEvent('conversation.item.create', {
                item: {
                    role: 'user',
                    content: [{ type: 'text', text: text }]
                }
            });

            this.sendWsEvent('response.create', { generate_audio: true });
        }

        sendQuickPrompt(promptText) {
            if (!this.isConnected) {
                this.connect();
                setTimeout(() => {
                    if (this.isConnected) {
                        const input = document.getElementById('textInput');
                        if (input) input.value = promptText;
                        this.sendTextMessage();
                    }
                }, 1200);
                return;
            }
            const input = document.getElementById('textInput');
            if (input) input.value = promptText;
            this.sendTextMessage();
        }

        toggleRecording() {
            if (this.isLiveCallActive || this.isRecording || this.isPlayingAudioSentence || this.isResponseStreamingActive) {
                this.endLiveConversation();
            } else {
                this.startLiveConversation();
            }
        }

        async startLiveConversation() {
            if (!this.isConnected) {
                this.addSystemMessage('⚠️ Conecte o WebSocket primeiro antes de iniciar a conversa de voz.', 'error');
                return;
            }

            try {
                if (!this.activeMicStream) {
                    this.activeMicStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                }
                this.isLiveCallActive = true;
                this.startListeningCycle();
            } catch (err) {
                console.error('Erro microfone:', err);
                this.addSystemMessage('⚠️ Não foi possível acessar o microfone. Verifique as permissões.', 'error');
                this.endLiveConversation();
            }
        }

        startListeningCycle() {
            if (!this.isLiveCallActive || !this.isConnected || this.isRecording) return;

            if (this.currentAudioPlayer) {
                try { this.currentAudioPlayer.pause(); } catch (e) {}
                this.currentAudioPlayer = null;
            }
            if (window.speechSynthesis && window.speechSynthesis.speaking) {
                try { window.speechSynthesis.cancel(); } catch (e) {}
            }
            this.audioSentenceQueue = [];
            this.isPlayingAudioSentence = false;

            this.isRecording = true;
            this.recordingStartTime = Date.now();
            this.recognizedText = '';
            this.isAudioSpeaking = false;
            this.lastAudioSoundTime = Date.now();

            const btn = document.getElementById('btnMic');
            if (btn) {
                btn.className = 'w-12 h-12 rounded-xl bg-red-600 hover:bg-red-500 border border-red-500 flex items-center justify-center transition-all cursor-pointer shrink-0 group relative recording-ring shadow-lg shadow-red-500/40';
                btn.title = '🎙️ Gravando... Fale livremente. A pausa envia na hora!';
            }
            const micIcon = document.getElementById('micIcon');
            if (micIcon) micIcon.className = 'fa-solid fa-microphone text-white text-lg animate-pulse';

            const waveContainer = document.getElementById('waveformContainer');
            if (waveContainer) {
                waveContainer.classList.remove('hidden');
                waveContainer.classList.add('flex');
            }
            const waveText = document.getElementById('waveformStatusText');
            if (waveText) waveText.textContent = '🎙️ Ouvindo você falar...';

            const textInput = document.getElementById('textInput');
            if (textInput) textInput.placeholder = '🎙️ Ouvindo você falar...';

            if (this.speechRecognizer) {
                try { this.speechRecognizer.start(); } catch (e) {}
            }

            const timerEl = document.getElementById('recordingTimer');
            if (timerEl) timerEl.textContent = '00:00';
            this.recordingTimerInterval = setInterval(() => {
                const elapsed = Math.floor((Date.now() - this.recordingStartTime) / 1000);
                const mins = String(Math.floor(elapsed / 60)).padStart(2, '0');
                const secs = String(elapsed % 60).padStart(2, '0');
                if (timerEl) timerEl.textContent = `${mins}:${secs}`;
            }, 500);

            let mimeType = 'audio/webm;codecs=opus';
            if (!MediaRecorder.isTypeSupported(mimeType)) {
                mimeType = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
            }

            this.audioChunks = [];
            try {
                this.mediaRecorder = mimeType ? new MediaRecorder(this.activeMicStream, { mimeType }) : new MediaRecorder(this.activeMicStream);
                this.mediaRecorder.ondataavailable = (e) => {
                    if (e.data && e.data.size > 0) this.audioChunks.push(e.data);
                };
                this.mediaRecorder.onstop = async () => {
                    const finalTranscript = this.recognizedText.trim();
                    if (finalTranscript && this.isConnected) {
                        const textEl = document.getElementById('textInput');
                        if (textEl) textEl.value = '';
                        this.requestStartTime = Date.now();
                        this.sendWsEvent('conversation.item.create', {
                            item: {
                                role: 'user',
                                content: [{ type: 'text', text: finalTranscript }]
                            }
                        });
                        this.sendWsEvent('response.create', { generate_audio: true });
                        return;
                    }

                    if (this.audioChunks.length > 0 && this.isConnected) {
                        const blob = new Blob(this.audioChunks, { type: this.mediaRecorder.mimeType || 'audio/webm' });
                        const reader = new FileReader();
                        reader.onloadend = () => {
                            const base64 = reader.result.split(',')[1];
                            if (base64 && this.isConnected) {
                                this.requestStartTime = Date.now();
                                this.addSystemMessage('🎙️ Enviando áudio e transcrevendo via IA...', 'processing');
                                this.sendWsEvent('input_audio_buffer.append', { audio: base64 });
                                this.sendWsEvent('input_audio_buffer.commit', {});
                            }
                        };
                        reader.readAsDataURL(blob);
                    }
                };
                this.mediaRecorder.start(250);
            } catch (e) {
                console.warn('MediaRecorder error:', e);
            }
        }

        finishSpeakingAndSend() {
            if (!this.isRecording) return;
            this.isRecording = false;

            if (this.recordingTimerInterval) { clearInterval(this.recordingTimerInterval); this.recordingTimerInterval = null; }
            if (this.vadSilenceTimer) { clearTimeout(this.vadSilenceTimer); this.vadSilenceTimer = null; }
            if (this.speechRecognizer) { try { this.speechRecognizer.stop(); } catch (e) {} }

            const btn = document.getElementById('btnMic');
            if (btn) {
                btn.className = 'w-12 h-12 rounded-xl bg-purple-600 hover:bg-purple-500 border border-purple-500 flex items-center justify-center transition-all cursor-pointer shrink-0 shadow-lg shadow-purple-500/25';
                btn.title = '⚡ IA respondendo... Clique para encerrar';
            }
            const micIcon = document.getElementById('micIcon');
            if (micIcon) micIcon.className = 'fa-solid fa-volume-high text-white text-lg animate-pulse';

            const waveText = document.getElementById('waveformStatusText');
            if (waveText) waveText.textContent = '⚡ IA gerando resposta...';

            const textInput = document.getElementById('textInput');
            if (textInput) textInput.placeholder = '⚡ IA respondendo por voz...';

            try {
                if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                    this.mediaRecorder.stop();
                }
            } catch (e) {}
        }

        endLiveConversation() {
            this.isLiveCallActive = false;
            this.isRecording = false;
            this.isResponseStreamingActive = false;

            this.sendWsEvent('response.cancel', {});

            if (this.currentAudioPlayer) {
                try { this.currentAudioPlayer.pause(); this.currentAudioPlayer.currentTime = 0; } catch (e) {}
                this.currentAudioPlayer = null;
            }
            this.audioSentenceQueue = [];
            this.isPlayingAudioSentence = false;

            if (window.speechSynthesis && window.speechSynthesis.speaking) {
                try { window.speechSynthesis.cancel(); } catch (e) {}
            }

            if (this.recordingTimerInterval) { clearInterval(this.recordingTimerInterval); this.recordingTimerInterval = null; }
            if (this.vadSilenceTimer) { clearTimeout(this.vadSilenceTimer); this.vadSilenceTimer = null; }
            if (this.speechRecognizer) { try { this.speechRecognizer.stop(); } catch (e) {} }
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') { try { this.mediaRecorder.stop(); } catch (e) {} }
            if (this.activeMicStream) {
                this.activeMicStream.getTracks().forEach(t => t.stop());
                this.activeMicStream = null;
            }

            const btn = document.getElementById('btnMic');
            if (btn) {
                btn.className = 'w-12 h-12 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 group relative shadow-md';
                btn.title = 'Clique para falar ao vivo';
            }
            const micIcon = document.getElementById('micIcon');
            if (micIcon) {
                micIcon.className = 'fa-solid fa-microphone text-gray-300 group-hover:text-cyan-400 transition text-lg';
            }

            const waveContainer = document.getElementById('waveformContainer');
            if (waveContainer) {
                waveContainer.classList.add('hidden');
                waveContainer.classList.remove('flex');
            }

            const textInput = document.getElementById('textInput');
            if (textInput) {
                textInput.placeholder = 'Digite uma mensagem ou clique no microfone para falar...';
            }
        }

        checkAutoContinueSpeech() {
            const handsFree = document.getElementById('cfgHandsFree')?.checked ?? true;
            if (this.isLiveCallActive && handsFree && this.isConnected && !this.isRecording && !this.isResponseStreamingActive && !this.isPlayingAudioSentence) {
                setTimeout(() => {
                    if (this.isLiveCallActive && handsFree && this.isConnected && !this.isRecording && !this.isPlayingAudioSentence && !this.isResponseStreamingActive) {
                        this.startListeningCycle();
                    }
                }, 350);
            }
        }

        enqueueSentenceAudio(base64Audio, transcript) {
            const selectedVoice = document.getElementById('cfgVoice')?.value || 'browser_fast';

            if (selectedVoice === 'browser_fast' && window.speechSynthesis && transcript) {
                const utter = new SpeechSynthesisUtterance(transcript);
                utter.lang = 'pt-BR';
                utter.rate = 1.1;
                this.isPlayingAudioSentence = true;
                utter.onend = () => {
                    this.isPlayingAudioSentence = false;
                    this.checkAutoContinueSpeech();
                };
                utter.onerror = () => {
                    this.isPlayingAudioSentence = false;
                    this.checkAutoContinueSpeech();
                };
                window.speechSynthesis.speak(utter);
                return;
            }

            if (base64Audio) {
                this.audioSentenceQueue.push({ base64: base64Audio, transcript: transcript });
                if (!this.isPlayingAudioSentence) {
                    this.playNextSentenceAudio();
                }
            }
        }

        playNextSentenceAudio() {
            if (this.audioSentenceQueue.length === 0) {
                this.isPlayingAudioSentence = false;
                this.checkAutoContinueSpeech();
                return;
            }

            this.isPlayingAudioSentence = true;
            const item = this.audioSentenceQueue.shift();

            try {
                const audioBlob = this.base64ToBlob(item.base64, 'audio/mpeg');
                const audioUrl = URL.createObjectURL(audioBlob);

                if (this.currentAudioPlayer) {
                    try { this.currentAudioPlayer.pause(); } catch(e) {}
                    this.currentAudioPlayer = null;
                }

                this.currentAudioPlayer = new Audio(audioUrl);
                this.currentAudioPlayer.playbackRate = 1.05;

                this.currentAudioPlayer.onended = () => {
                    URL.revokeObjectURL(audioUrl);
                    this.playNextSentenceAudio();
                };

                this.currentAudioPlayer.onerror = () => {
                    URL.revokeObjectURL(audioUrl);
                    this.playNextSentenceAudio();
                };

                const playPromise = this.currentAudioPlayer.play();
                if (playPromise !== undefined) {
                    playPromise.catch(() => {
                        this.isPlayingAudioSentence = false;
                    });
                }
            } catch (err) {
                console.error('Erro áudio:', err);
                this.playNextSentenceAudio();
            }
        }

        base64ToBlob(base64, mimeType) {
            const byteChars = atob(base64);
            const byteArrays = [];
            for (let offset = 0; offset < byteChars.length; offset += 512) {
                const slice = byteChars.slice(offset, offset + 512);
                const byteNumbers = new Array(slice.length);
                for (let i = 0; i < slice.length; i++) {
                    byteNumbers[i] = slice.charCodeAt(i);
                }
                byteArrays.push(new Uint8Array(byteNumbers));
            }
            return new Blob(byteArrays, { type: mimeType });
        }

        addChatMessage(role, text, isStreaming = false) {
            if (role === 'user') {
                const clean = (text || '').trim();
                const now = Date.now();
                if (clean && this.lastRenderedUserMsg.text === clean && (now - this.lastRenderedUserMsg.time < 2500)) {
                    return null;
                }
                this.lastRenderedUserMsg = { text: clean, time: now };
            }

            const container = document.getElementById('chatMessages');
            if (!container) return null;
            if (container.querySelector('.text-gray-600')) container.innerHTML = '';

            const msgDiv = document.createElement('div');
            msgDiv.className = `msg-animate flex ${role === 'user' ? 'justify-end' : 'justify-start'}`;
            msgDiv.setAttribute('data-role', role);

            const isUser = role === 'user';
            const bubbleClasses = isUser
                ? 'bg-gradient-to-br from-indigo-600/30 to-purple-600/20 border-indigo-500/40 text-gray-100 shadow-md'
                : 'bg-gray-800/80 border-gray-700/70 text-gray-200 shadow-md';

            const icon = isUser
                ? '<i class="fa-solid fa-user text-indigo-400"></i>'
                : '<i class="fa-solid fa-bolt text-cyan-400"></i>';

            const title = isUser ? 'Você' : 'Assistente Realtime';

            msgDiv.innerHTML = `
                <div class="max-w-[85%] sm:max-w-[75%] ${isUser ? 'order-2' : ''}">
                    <div class="flex items-center gap-2 mb-1.5 ${isUser ? 'justify-end' : ''}">
                        <span class="w-6 h-6 rounded-lg ${isUser ? 'bg-indigo-500/20' : 'bg-cyan-500/20'} flex items-center justify-center text-xs shadow">${icon}</span>
                        <span class="text-[11px] text-gray-400 font-bold">${title}</span>
                    </div>
                    <div class="px-4 py-3 rounded-2xl border ${bubbleClasses} text-sm leading-relaxed ${isStreaming ? 'streaming-cursor' : ''}" data-content>
                        ${this.escapeHtml(text || '')}
                    </div>
                    <div class="msg-footer flex items-center gap-2 mt-1.5 px-1 ${isUser ? 'justify-end' : 'justify-start'}">
                        ${!isUser ? `
                            <button onclick="window.realtimeConsole.copyMsgText(this)" class="text-[11px] text-gray-500 hover:text-gray-300 transition flex items-center gap-1 cursor-pointer" title="Copiar texto">
                                <i class="fa-solid fa-copy"></i>
                            </button>
                            <button onclick="window.realtimeConsole.speakMsgText(this)" class="text-[11px] text-gray-500 hover:text-cyan-400 transition flex items-center gap-1 cursor-pointer" title="Ouvir novamente">
                                <i class="fa-solid fa-volume-high"></i>
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;

            container.appendChild(msgDiv);
            container.scrollTop = container.scrollHeight;
            return msgDiv;
        }

        appendToStreamingMessage(el, delta) {
            const contentEl = el.querySelector('[data-content]');
            if (contentEl) {
                contentEl.textContent += delta;
            }
            const container = document.getElementById('chatMessages');
            if (container) container.scrollTop = container.scrollHeight;
        }

        finalizeStreamingMessage(el) {
            const contentEl = el.querySelector('[data-content]');
            if (contentEl) contentEl.classList.remove('streaming-cursor');
        }

        copyMsgText(btn) {
            const bubble = btn.closest('.max-w-[85%], .max-w-[75%]')?.querySelector('[data-content]');
            if (bubble) {
                navigator.clipboard.writeText(bubble.textContent.trim());
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-check text-emerald-400"></i>';
                setTimeout(() => btn.innerHTML = orig, 1500);
            }
        }

        speakMsgText(btn) {
            const bubble = btn.closest('.max-w-[85%], .max-w-[75%]')?.querySelector('[data-content]');
            if (bubble && window.speechSynthesis) {
                window.speechSynthesis.cancel();
                const utter = new SpeechSynthesisUtterance(bubble.textContent.trim());
                utter.lang = 'pt-BR';
                utter.rate = 1.1;
                window.speechSynthesis.speak(utter);
            }
        }

        addSystemMessage(text, msgType = 'info') {
            const container = document.getElementById('chatMessages');
            if (!container) return;
            if (container.querySelector('.text-gray-600')) container.innerHTML = '';

            const msgDiv = document.createElement('div');
            msgDiv.className = 'msg-animate flex justify-center';
            if (msgType) msgDiv.setAttribute('data-msg-type', msgType);

            const colors = {
                info: 'bg-gray-800/80 text-gray-300 border-gray-700/60',
                error: 'bg-red-500/15 text-red-400 border-red-500/30',
                processing: 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30',
                websearch: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
            };

            msgDiv.innerHTML = `
                <div class="px-3.5 py-1.5 rounded-xl border text-xs ${colors[msgType] || colors.info} shadow">
                    ${text}
                </div>
            `;

            container.appendChild(msgDiv);
            container.scrollTop = container.scrollHeight;
        }

        removeSystemMessage(msgType) {
            const container = document.getElementById('chatMessages');
            if (!container) return;
            const el = container.querySelector(`[data-msg-type="${msgType}"]`);
            if (el) el.remove();
        }

        clearChat() {
            const container = document.getElementById('chatMessages');
            if (container) {
                container.innerHTML = `
                    <div class="flex items-center justify-center h-full text-gray-600">
                        <div class="text-center space-y-3 max-w-md mx-auto">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-cyan-500/20 via-indigo-600/20 to-purple-600/20 flex items-center justify-center mx-auto border border-cyan-500/30 shadow-lg shadow-cyan-500/10">
                                <i class="fa-solid fa-bolt text-2xl text-cyan-400"></i>
                            </div>
                            <h3 class="text-base font-bold text-gray-200">Conversa Limpa</h3>
                            <p class="text-xs text-gray-400">Pronto para iniciar uma nova conversa em tempo real.</p>
                        </div>
                    </div>
                `;
            }
            this.audioSentenceQueue = [];
        }

        logEvent(type, data, source = 'server') {
            this.eventCounter++;
            const container = document.getElementById('eventLog');
            if (!container) return;
            if (container.querySelector('.text-gray-600')) container.innerHTML = '';

            const now = new Date().toLocaleTimeString('pt-BR', { hour12: false });
            const isServer = source === 'server';

            const div = document.createElement('div');
            div.className = 'p-2 rounded-lg bg-gray-950/80 border border-gray-800/80 hover:border-gray-700 transition';

            div.innerHTML = `
                <div class="flex items-center justify-between text-[10px] mb-1">
                    <span class="${isServer ? 'text-cyan-400' : 'text-purple-400'} font-bold flex items-center gap-1">
                        <i class="fa-solid ${isServer ? 'fa-arrow-down' : 'fa-arrow-up'} text-[9px]"></i>
                        ${type}
                    </span>
                    <span class="text-gray-600">${now}</span>
                </div>
                <pre class="text-[10px] text-gray-400 overflow-x-auto max-h-24 custom-scrollbar whitespace-pre-wrap">${this.escapeHtml(JSON.stringify(data, null, 2))}</pre>
            `;

            container.insertBefore(div, container.firstChild);
            const countEl = document.getElementById('eventCount');
            if (countEl) countEl.textContent = `${this.eventCounter} eventos`;
        }

        clearEventLog() {
            this.eventCounter = 0;
            const container = document.getElementById('eventLog');
            if (container) {
                container.innerHTML = `
                    <div class="text-center text-gray-600 py-16 font-sans">
                        <i class="fa-solid fa-terminal text-2xl mb-2 block text-gray-700"></i>
                        <p class="text-xs">Log de eventos limpo</p>
                    </div>
                `;
            }
            const countEl = document.getElementById('eventCount');
            if (countEl) countEl.textContent = '0';
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        setStatus(status) {
            const statusEl = document.getElementById('connectionStatus');
            const dot = document.getElementById('statusDot');
            const text = document.getElementById('statusText');
            const btn = document.getElementById('btnConnect');
            if (!statusEl || !btn) return;

            const configs = {
                disconnected: {
                    bg: 'bg-red-500/10 border-red-500/30 text-red-400',
                    dot: 'bg-red-400',
                    text: 'Desconectado',
                    btn: '<i class="fa-solid fa-plug"></i> Conectar',
                    btnClass: 'bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500'
                },
                connecting: {
                    bg: 'bg-amber-500/10 border-amber-500/30 text-amber-400',
                    dot: 'bg-amber-400 status-pulse',
                    text: 'Conectando...',
                    btn: '<i class="fa-solid fa-spinner fa-spin"></i> Conectando...',
                    btnClass: 'bg-amber-600/70 cursor-wait'
                },
                connected: {
                    bg: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400',
                    dot: 'bg-emerald-400 status-pulse',
                    text: 'Conectado',
                    btn: '<i class="fa-solid fa-plug-circle-xmark"></i> Desconectar',
                    btnClass: 'bg-red-600/80 hover:bg-red-500'
                },
                error: {
                    bg: 'bg-red-500/10 border-red-500/30 text-red-400',
                    dot: 'bg-red-400',
                    text: 'Erro de Conexão',
                    btn: '<i class="fa-solid fa-plug"></i> Tentar Novamente',
                    btnClass: 'bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500'
                }
            };

            const cfg = configs[status] || configs.disconnected;
            statusEl.className = `flex items-center space-x-1.5 px-2.5 py-1 rounded-xl border transition-all duration-300 font-semibold text-xs ${cfg.bg}`;
            if (dot) dot.className = `w-2 h-2 rounded-full ${cfg.dot}`;
            if (text) text.textContent = cfg.text;
            btn.innerHTML = cfg.btn;
            btn.className = `px-3.5 py-1.5 rounded-xl ${cfg.btnClass} text-white font-bold text-xs shadow-md transition flex items-center gap-1.5 cursor-pointer`;
        }

        setControlsEnabled(enabled) {
            const input = document.getElementById('textInput');
            const btnSend = document.getElementById('btnSend');
            const btnMic = document.getElementById('btnMic');
            if (input) input.disabled = !enabled;
            if (btnSend) btnSend.disabled = !enabled;
            if (btnMic) btnMic.disabled = !enabled;
        }

        updateUsage() {
            const tok = document.getElementById('usageTokens');
            const msg = document.getElementById('usageMessages');
            if (tok) tok.textContent = this.totalTokens.toLocaleString('pt-BR');
            if (msg) msg.textContent = this.totalMessages;
        }

        toggleLogPanel() {
            const panel = document.getElementById('logPanel');
            if (panel) {
                panel.classList.toggle('hidden');
                const isHidden = panel.classList.contains('hidden');
                const toggleBtn = document.getElementById('logToggleText');
                if (toggleBtn) toggleBtn.textContent = isHidden ? 'Ver Log' : 'Log';
            }
        }

        openInstructionsModal() {
            const modal = document.getElementById('modalInstructions');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }

        closeInstructionsModal() {
            const modal = document.getElementById('modalInstructions');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }

        saveAndApplyInstructions() {
            this.closeInstructionsModal();
            this.updateSession();
            this.addSystemMessage('✨ Instruções salvas e aplicadas na sessão!', 'info');
        }
    }

    // Expor globalmente
    window.BRLoveRealtimeConsole = RealtimeConsole;

    // Atalhos globais para botões HTML inline
    window.toggleConnection = () => window.realtimeConsole?.toggleConnection();
    window.toggleRecording = () => window.realtimeConsole?.toggleRecording();
    window.sendTextMessage = () => window.realtimeConsole?.sendTextMessage();
    window.sendQuickPrompt = (txt) => window.realtimeConsole?.sendQuickPrompt(txt);
    window.clearChat = () => window.realtimeConsole?.clearChat();
    window.toggleLogPanel = () => window.realtimeConsole?.toggleLogPanel();
    window.clearEventLog = () => window.realtimeConsole?.clearEventLog();
    window.openInstructionsModal = () => window.realtimeConsole?.openInstructionsModal();
    window.closeInstructionsModal = () => window.realtimeConsole?.closeInstructionsModal();
    window.saveAndApplyInstructions = () => window.realtimeConsole?.saveAndApplyInstructions();
    window.setVoiceMode = (m) => window.realtimeConsole?.setVoiceMode(m);
    window.onVoiceSelectChange = () => window.realtimeConsole?.onVoiceSelectChange();
    window.copyMsgText = (b) => window.realtimeConsole?.copyMsgText(b);
    window.speakMsgText = (b) => window.realtimeConsole?.speakMsgText(b);

})(window);

