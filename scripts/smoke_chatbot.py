"""Exercise the same Vite proxy used by the browser; writes only chat sessions."""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import json
import time
import urllib.error
import urllib.request
import uuid

BASE = "http://127.0.0.1:5173"
OUTPUT = Path(__file__).resolve().parents[1] / "docs" / "chatbot-smoke-results.json"


def validate_results(results):
    chats = [item for item in results if 'chat' in item]
    assert len(chats) == 5
    for item in chats:
        assert item['chat']['status'] == 200 and item['business_during_chat']['status'] == 200 and item['replay_same_message']
        assert item['chat']['body']['data']['content'].strip()
    price = chats[1]['chat']['body']['data']['content']
    assert '69.000' in price or '69,000' in price
    assert '/dat-ban' in chats[2]['chat']['body']['data']['content']
    guest = chats[4]['chat']['body']['data']
    assert 'đăng nhập' in guest['content'] and not guest.get('pending_actions')
    assert results[-1]['history_status'] == 200 and results[-1]['message_count'] == 10


def request(path, payload=None, headers=None, timeout=90):
    started = time.monotonic()
    req = urllib.request.Request(BASE + path,
        data=json.dumps(payload).encode() if payload is not None else None,
        headers={"Accept": "application/json", "Content-Type": "application/json", **(headers or {})})
    try:
        response = urllib.request.urlopen(req, timeout=timeout)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return {"status": response.status, "seconds": round(time.monotonic() - started, 2), "body": json.load(response)}


def main():
    results = []
    created = request('/api/chatbot/sessions', {})
    assert created['status'] == 201, created
    session = created['body']['data']
    base = f"/api/chatbot/sessions/{session['id']}"
    headers = {'X-Chat-Guest-Token': session['guest_token']}
    try:
        for question in ['Menu có món gì ngon?', 'Mì cay bò nhỏ giá bao nhiêu?', 'Đặt bàn như thế nào?', 'Có khuyến mãi gì không?', 'Thêm 1 mì cay bò nhỏ vào giỏ cho tôi']:
            payload = {'message': question, 'use_tools': True, 'client_message_id': str(uuid.uuid4())}
            with ThreadPoolExecutor() as executor:
                pending = executor.submit(request, base + '/messages', payload, headers)
                time.sleep(1)
                health = request('/api/menu', timeout=5)
                result = pending.result()
            replay = request(base + '/messages', payload, headers) if result['status'] == 200 else None
            item = {'question': question, 'chat': result, 'business_during_chat': {'status': health['status'], 'seconds': health['seconds']},
                    'replay_same_message': replay is not None and replay['body'].get('data', {}).get('message_id') == result['body'].get('data', {}).get('message_id')}
            results.append(item)
            print(json.dumps(item, ensure_ascii=True), flush=True)
        history = request(base, headers=headers)
        results.append({'history_status': history['status'], 'message_count': len(history['body'].get('data', {}).get('messages', []))})
    finally:
        request(base + '/close', {}, headers)
        OUTPUT.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding='utf-8')
    validate_results(results)


if __name__ == '__main__':
    main()
