/* MARKER-WEB-TEMPLATES: draws the sample shop for master admin › Site templates › Preview. */
window.SiteSample = (function () {
  var W = null, M = 'dark', page = 'home';
  var pc = function (k) { return W.pieces[k]; };
  var col = function (k, m) { return W.modes[m || M][k]; };
  var prevMode = function () { return M; };
const ICONS={
 ring:'<svg viewBox="0 0 100 100" fill="none"><circle cx="50" cy="50" r="38" stroke="currentColor" stroke-width="7" stroke-dasharray="5 3.2"/><circle cx="50" cy="50" r="27" stroke="currentColor" stroke-width="8"/>'+[0,72,144,216,288].map(a=>`<circle cx="${50+27*Math.cos(a*Math.PI/180)}" cy="${50+27*Math.sin(a*Math.PI/180)}" r="3.4" fill="#fff"/>`).join('')+'</svg>',
 tire:'<svg viewBox="0 0 100 100" fill="none"><circle cx="50" cy="50" r="40" stroke="currentColor" stroke-width="10" stroke-dasharray="3 2.4"/><circle cx="50" cy="50" r="34" stroke="currentColor" stroke-width="3" opacity=".6"/></svg>',
 bottle:'<svg viewBox="0 0 100 100" fill="currentColor"><rect x="38" y="10" width="24" height="9" rx="2"/><path d="M34 26h32l2 8v52a6 6 0 0 1-6 6H38a6 6 0 0 1-6-6V34z"/><rect x="34" y="44" width="32" height="16" fill="#fff" opacity=".35"/></svg>',
 lamp:'<svg viewBox="0 0 100 100" fill="currentColor"><rect x="30" y="34" width="40" height="30" rx="8"/><circle cx="50" cy="49" r="8" fill="#fff" opacity=".5"/><rect x="44" y="64" width="12" height="14"/><rect x="32" y="78" width="36" height="6" rx="3"/></svg>',
 bag:'<svg viewBox="0 0 100 100" fill="currentColor"><path d="M26 36h48l-4 48H30z"/><path d="M38 36v-6a12 12 0 0 1 24 0v6" fill="none" stroke="currentColor" stroke-width="5"/></svg>',
 tool:'<svg viewBox="0 0 100 100" fill="currentColor"><path d="M62 18a16 16 0 0 0-15 21L20 66a7 7 0 0 0 10 10l27-27a16 16 0 0 0 21-15l-9 9-9-2-2-9z"/></svg>',
};
const PRODUCTS=[['Race Face','Narrow-wide chainring 32t','$72',null,'ring','Special order'],['Maxxis','Minion trail tire 29 x 2.4','$89','$104','tire','Sale'],['Silca','Insulated bottle 750ml','$24',null,'bottle',null],['Lezyne','Front light 1000 lumen','$65',null,'lamp','New'],['Ortlieb','Frame bag 4L','$110',null,'bag',null],['Park Tool','Multi-tool 21 function','$42',null,'tool',null],['Maxxis','Assegai tire 29 x 2.5','$95',null,'tire','Special order'],['SRAM','Chainring 34t 104mm','$58',null,'ring',null]];
const tile=(p,hl)=>`<div class="pt"><div class="im">${p[5]?`<span class="bdg ${p[5]==='Sale'?'s':''}">${p[5]}</span>`:''}${ICONS[p[4]]}</div>${hl?'':`<div class="br">${p[0]}</div>`}<div class="nm">${hl?p[0]+' '+p[1].replace(new RegExp('('+hl+')','i'),'<b>$1</b>'):p[1]}</div><div class="p">${p[2]}${p[3]?`<s>${p[3]}</s>`:''}</div></div>`;
function navHtml(active){
 return `<div class="nv"><span class="lg">Ground Control</span><span class="lk"><span>Home</span><span>Service</span><span>Shop</span><span>Contact</span></span>
 <span class="srch ${active?'act':''}"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="7" cy="7" r="5"/><path d="M11 11l3.5 3.5"/></svg>${active?'tire':'Search the shop'}</span>
 <span class="sg">Sign in</span><span class="btn p bk">Book now</span>
 ${active?dropdown():''}</div>`;
}
function dropdown(){
 const tires=PRODUCTS.filter(p=>p[4]==='tire');const list=[tires[0],tires[1],tires[0],tires[1],tires[0],tires[1]].map((p,i)=>{const q=[...p];q[1]=['Minion trail tire 29 x 2.4','Assegai tire 29 x 2.5','Dissector tire 27.5 x 2.4','Rekon tire 29 x 2.6','High Roller tire 29 x 2.4','Aggressor tire 27.5 x 2.5'][i];q[2]=['$89','$95','$82','$78','$86','$74'][i];q[3]=null;q[5]=null;return q});
 return `<div class="dd"><div class="l"><h6>Suggestions</h6><div class="sug"><b>tire</b>s</div><div class="sug"><b>tire</b> levers</div><div class="sug"><b>tire</b> sealant</div>
 <h6 style="margin-top:16px">Categories</h6><div class="ct"><i>${ICONS.tire}</i>29" tires<span class="mu" style="margin-left:auto">34</span></div><div class="ct"><i>${ICONS.tire}</i>27.5" tires<span class="mu" style="margin-left:auto">21</span></div><div class="ct"><i>${ICONS.tool}</i>Tire tools<span class="mu" style="margin-left:auto">9</span></div></div>
 <div class="r"><h6>Products</h6><div class="grid">${list.map(p=>tile(p,'tire')).join('')}</div></div><div class="all">See all 170 results →</div></div>`;
}
function heroHtml(){
 const h=pc('hero');
 if(h==='photo')return `<div class="hero-p ph"><div class="ov"></div><div class="in"><h1>Service that comes to you.</h1><p>Tune-ups, overhauls and repairs. We pick up from your door and bring it back ready to ride.</p><div class="row"><span class="btn p">Schedule a pickup →</span><span class="btn g" style="color:#fff;border-color:rgba(255,255,255,.45)">See pricing</span></div></div></div>`;
 if(h==='split')return `<div class="hero-s"><div><div class="eyb">Mobile service</div><h1>Service that comes to you.</h1><p>Tune-ups, overhauls and repairs. We pick up from your door and bring it back ready to ride.</p><div class="row"><span class="btn p">Schedule a pickup →</span><span class="btn g">See pricing</span></div></div><div class="ph"></div></div>`;
 return `<div class="hero-t"><div class="ey">Mobile service</div><h1>Service that comes to you.</h1><p>Tune-ups, overhauls and repairs. We pick up from your door and bring it back ready to ride.</p><div class="row"><span class="btn p">Schedule a pickup →</span><span class="btn g">See pricing</span></div></div>`;
}
function footHtml(){
 const f=pc('footer');
 if(f==='simple')return `<div class="ft"><div class="wrap in"><span class="lg">Ground Control</span><span>Service</span><span>Shop</span><span>Contact</span><span class="sp"></span><span>© 2026 Ground Control</span></div></div>`;
 if(f==='columns')return `<div style="border-top:1px solid var(--bd)"><div class="wrap ftc"><div><div class="lg" style="font-size:20px;margin-bottom:8px">Ground Control</div><div class="mu" style="font-size:13.5px">Mobile service, pickup and drop-off across town.</div></div><div><h4>Shop</h4><a>All products</a><a>On sale</a><a>Gift cards</a></div><div><h4>Service</h4><a>Pricing</a><a>Book</a><a>Contact</a></div><div><h4>Stay in the loop</h4><div class="nl"><span>Email address</span><span class="btn p" style="flex:none;padding:9px 14px">Join</span></div></div></div><div class="wrap" style="padding-bottom:22px;font-size:12.5px;color:var(--mu)">© 2026 Ground Control</div></div>`;
 return `<div class="ftcta"><div class="wrap in"><h2>Ready when you are.</h2><span class="btn">Book a pickup →</span></div></div><div class="ft" style="border-top:0"><div class="wrap in"><span class="lg">Ground Control</span><span>Service</span><span>Shop</span><span>Contact</span><span class="sp"></span><span>© 2026 Ground Control</span></div></div>`;
}
function cardsHtml(){
 return [['Standard service','$140 & up','Gears and brakes adjusted, everything checked and cleaned.'],['Full overhaul','$260 & up','Stripped down, every bearing and cable seen to.'],['Pickup and drop-off','Free over $100','We collect from your door and bring it back.']].map(c=>`<div class="cd"><div class="im ph"></div><div class="tx"><h3>${c[0]}</h3><div class="pr">${c[1]}</div><p>${c[2]}</p></div></div>`).join('');
}
function siteWrap(body,heroOn){
 const m=prevMode();const c=k=>col(k,m);
 const fld=`color-mix(in srgb,${c('s1')} 80%,${c('tx')} 6%)`;
 const vars=`--bg:${c('bg')};--s1:${c('s1')};--s2:${c('s2')};--tx:${c('tx')};--mu:${c('mu')};--bd:${c('bd')};--ac:${c('ac')};--at:${c('at')};--fld:${fld};--fh:'${pc('fh')}';--fb:'${pc('fb')}';--hw:${pc('hw')};--hc:${pc('hc')==='upper'?'uppercase':'none'};--hls:${pc('hc')==='upper'?'.01em':(pc('fh')==='Inter'?'-.025em':'-.01em')};--r:${pc('r')}px;--br:${pc('btn')==='solid'&&pc('r')>=16?'99px':Math.min(pc('r'),12)+'px'};--ratio:${pc('ratio')};--cols:${pc('cols')};--shd:${m==='dark'?55:10}`;
 const cls=`sts-site nav-${pc('nav')} hero-${heroOn?pc('hero'):'none'} cards-${pc('cards')} tiles-${pc('tiles')} btn-${pc('btn')}`;
 return `<div class="${cls}" style="${vars}">${body}</div>`;
}

  function render(el, work, pg, mode) {
    W = work; M = mode || 'dark'; page = pg || 'home';
    var body, heroOn = true;
    if (page === 'home' || page === 'search') {
      body = '<div class="hdr">' + navHtml(page === 'search') + heroHtml() + '</div>'
        + '<div class="sec"><div class="wrap"><div class="eyb">What we do</div><h2>Popular this season</h2><div class="cards">' + cardsHtml() + '</div></div></div>'
        + '<div class="sec alt"><div class="wrap"><div class="eyb">From the shop</div><h2>New in</h2><div class="grid">' + PRODUCTS.slice(0, pc('cols')).map(function (p) { return tile(p); }).join('') + '</div></div></div>' + footHtml();
    } else {
      heroOn = false;
      body = '<div class="hdr">' + navHtml(false) + '</div><div class="wrap"><div class="crumb">Shop › Tires</div><div class="shead"><h1>Tires</h1><span class="mu">186 items</span></div>'
        + '<div class="sbar"><span class="chip">Tires ✕</span><span class="sp"></span><span class="sel">Featured ▾</span></div>'
        + '<div class="slay"><div class="fac"><div class="g"><h5>Category</h5><div class="it"><span class="bx"></span>Drivetrain<span class="n">412</span></div><div class="it sub"><span class="bx"></span>Chainrings<span class="n">196</span></div><div class="it on"><span class="bx"></span>Tires<span class="n">186</span></div><div class="it sub"><span class="bx"></span>29"<span class="n">157</span></div><div class="it"><span class="bx"></span>Accessories<span class="n">88</span></div></div>'
        + '<div class="g" style="border:0"><h5>Availability</h5><div class="it"><span class="bx"></span>In stock<span class="n">42</span></div><div class="it"><span class="bx"></span>Special order<span class="n">144</span></div></div></div>'
        + '<div class="grid" style="--cols:' + Math.max(3, pc('cols') - 1) + '">' + PRODUCTS.map(function (p) { return tile(p); }).join('') + '</div></div></div>' + footHtml();
    }
    el.innerHTML = siteWrap(body, heroOn);
  }
  return { render: render };
})();
