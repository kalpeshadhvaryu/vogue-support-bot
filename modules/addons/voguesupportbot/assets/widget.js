(function () {
    "use strict";

    if (window.__vogueSupportBot) {
        return;
    }
    window.__vogueSupportBot = true;

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var endpoint = safeUrl(script.getAttribute("data-endpoint") || "");
    var channel = script.getAttribute("data-channel") || "website";
    var csrf = script.getAttribute("data-csrf") || "";
    var title = script.getAttribute("data-title") || "Vogue Hosting";
    var greeting = script.getAttribute("data-greeting") || "How can we help?";
    if (!endpoint) {
        return;
    }

    var storageKey = "vsb:" + endpoint;
    var state = loadState();
    var messages = [];
    var sending = false;

    if (!document.body) {
        document.addEventListener("DOMContentLoaded", start);
        return;
    }
    start();

    function start() {
    injectStyles();
    var root = document.createElement("div");
    root.className = "vsb";
    root.innerHTML = [
        '<button type="button" class="vsb-launcher" aria-expanded="false" aria-controls="vsb-panel">',
        '<span class="vsb-launcher-label"></span>',
        "</button>",
        '<section class="vsb-panel" id="vsb-panel" hidden>',
        '<header class="vsb-header">',
        '<strong class="vsb-title"></strong>',
        '<button type="button" class="vsb-close" aria-label="Close chat">&times;</button>',
        "</header>",
        '<div class="vsb-log" role="log" aria-live="polite"></div>',
        '<form class="vsb-form">',
        '<label class="vsb-sr" for="vsb-input">Message</label>',
        '<textarea id="vsb-input" maxlength="2000" rows="2" placeholder="Write a message"></textarea>',
        '<button type="submit">Send</button>',
        "</form>",
        '<p class="vsb-note">Automated assistant. Ask to talk to a person if you need one.</p>',
        "</section>"
    ].join("");
    document.body.appendChild(root);

    var launcher = root.querySelector(".vsb-launcher");
    var panel = root.querySelector(".vsb-panel");
    var log = root.querySelector(".vsb-log");
    var form = root.querySelector(".vsb-form");
    var input = root.querySelector("#vsb-input");
    var titleNode = root.querySelector(".vsb-title");
    var launcherLabel = root.querySelector(".vsb-launcher-label");
    titleNode.textContent = title;
    launcherLabel.textContent = "Chat";
    addBubble("assistant", greeting);

    launcher.addEventListener("click", function () {
        setOpen(panel.hidden);
    });
    root.querySelector(".vsb-close").addEventListener("click", function () {
        setOpen(false);
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !panel.hidden) {
            setOpen(false);
        }
    });
    form.addEventListener("submit", function (event) {
        event.preventDefault();
        send();
    });
    input.addEventListener("keydown", function (event) {
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();
            send();
        }
    });

    if (state.id) {
        post({ action: "history", conversation_id: state.id, conversation_token: state.token, csrf_token: csrf })
            .then(function (data) {
                if (data && data.error === "conversation_rejected") {
                    state = { id: "", token: "" };
                    saveState();
                    return;
                }
                if (!data || !data.ok || !Array.isArray(data.messages)) {
                    return;
                }
                messages = [];
                log.textContent = "";
                addBubble("assistant", greeting);
                data.messages.forEach(function (message) {
                    if (message && (message.role === "user" || message.role === "assistant") && typeof message.content === "string") {
                        addBubble(message.role, message.content);
                    }
                });
            })
            .catch(function () {});
    }

    function setOpen(open) {
        panel.hidden = !open;
        launcher.setAttribute("aria-expanded", open ? "true" : "false");
        if (open) {
            input.focus();
        }
    }

    function send() {
        var text = input.value.trim();
        if (!text || sending) {
            return;
        }
        sending = true;
        addBubble("user", text);
        input.value = "";
        var pending = addBubble("assistant", "…");
        post({
            action: "message",
            message: text,
            conversation_id: state.id,
            conversation_token: state.token,
            csrf_token: csrf
        }).then(function (data) {
            if (data && data.error === "conversation_rejected") {
                state = { id: "", token: "" };
                saveState();
            }
            pending.textContent = data && typeof data.reply === "string"
                ? data.reply
                : "Something went wrong. Please try again.";
            if (data && data.conversation_id) {
                state.id = data.conversation_id;
            }
            if (data && data.conversation_token) {
                state.token = data.conversation_token;
            }
            saveState();
        }).catch(function () {
            pending.textContent = "I could not reach the assistant. Please try again.";
        }).then(function () {
            sending = false;
        });
    }

    function addBubble(role, text) {
        var item = document.createElement("div");
        item.className = "vsb-msg vsb-msg-" + (role === "user" ? "user" : "assistant");
        item.textContent = text;
        log.appendChild(item);
        log.scrollTop = log.scrollHeight;
        messages.push(item);
        return item;
    }
    }

    function post(payload) {
        return fetch(endpoint, {
            method: "POST",
            credentials: channel === "clientarea" ? "same-origin" : "omit",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, reply: "The assistant sent an unexpected response." };
            });
        });
    }

    function loadState() {
        try {
            var saved = JSON.parse(window.sessionStorage.getItem(storageKey) || "{}");
            return {
                id: typeof saved.id === "string" ? saved.id : "",
                token: typeof saved.token === "string" ? saved.token : ""
            };
        } catch (error) {
            return { id: "", token: "" };
        }
    }

    function saveState() {
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(state));
        } catch (error) {}
    }

    function safeUrl(value) {
        try {
            var url = new URL(value, window.location.href);
            if (url.protocol !== "https:" && url.protocol !== "http:") {
                return "";
            }
            return url.toString();
        } catch (error) {
            return "";
        }
    }

    function injectStyles() {
        var style = document.createElement("style");
        style.textContent = [
            ".vsb{font-family:inherit;font-size:15px;color:#1c1915;}",
            ".vsb-launcher{position:fixed;right:20px;bottom:20px;z-index:2147483000;border:0;border-radius:999px;background:#142033;color:#f6f1e7;padding:12px 18px;cursor:pointer;box-shadow:0 8px 24px rgba(20,32,51,.28);}",
            ".vsb-panel{position:fixed;right:20px;bottom:76px;z-index:2147483000;width:min(380px,calc(100vw - 24px));height:min(560px,calc(100vh - 110px));background:#fffdf8;border:1px solid #e4dccb;border-radius:16px;box-shadow:0 16px 40px rgba(20,32,51,.2);display:flex;flex-direction:column;overflow:hidden;}",
            ".vsb-header{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:#142033;color:#f6f1e7;}",
            ".vsb-close{background:transparent;border:0;color:inherit;font-size:22px;cursor:pointer;line-height:1;}",
            ".vsb-log{flex:1;overflow:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:#f7f3ea;}",
            ".vsb-msg{max-width:85%;padding:8px 10px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word;}",
            ".vsb-msg-assistant{align-self:flex-start;background:#fff;border:1px solid #e4dccb;}",
            ".vsb-msg-user{align-self:flex-end;background:#142033;color:#f6f1e7;}",
            ".vsb-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e4dccb;}",
            ".vsb-form textarea{flex:1;resize:none;border:1px solid #d9d0c1;border-radius:10px;padding:8px;font:inherit;}",
            ".vsb-form button{border:0;border-radius:10px;background:#8c6a32;color:#fff;padding:0 14px;cursor:pointer;}",
            ".vsb-note{margin:0;padding:0 12px 10px;color:#6d6458;font-size:12px;}",
            ".vsb-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0;}",
            "@media (max-width:520px){.vsb-panel{right:8px;bottom:72px;width:calc(100vw - 16px);height:calc(100vh - 96px);}}"
        ].join("");
        document.head.appendChild(style);
    }
})();
