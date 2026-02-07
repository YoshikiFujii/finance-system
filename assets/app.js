// ログインAPI呼び出し例
async function login(role, password){
const r = await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({role,password})});
const j = await r.json(); if(!j.ok) throw new Error(j.message); return j.data;
}