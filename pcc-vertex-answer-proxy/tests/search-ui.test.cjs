const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
function setup() {
  let now = 0, tick, finish;
  const node = (tag = 'div', value = '') => ({style:{},dataset:{},attrs:{},hidden:false,
    tagName:tag,nodeType:tag === '#text' ? 3 : 1,childNodes:[],value,
    get textContent(){return this.nodeType === 3 ? this.value : this.childNodes.map(n=>n.textContent).join('')},
    set textContent(v){this.childNodes=[node('#text',v)]},
    appendChild(n){this.childNodes.push(n);return n},
    cloneNode(){const n=node(this.tagName);n.attrs={...this.attrs};return n},
    removeAttribute(k){delete this.attrs[k]},
    after(n){this.next=n},
    setAttribute(k,v){this.attrs[k]=String(v)}, getAttribute(k){return this.attrs[k]},
    addEventListener(k,fn){this[k]=fn}, remove(){this.removed=true}});
  const context = {window:{}, URL, location:{href:"https://pasadena.edu/search.php"}, document:{currentScript:{src:"https://example.com/public/search-ui.js"},createElement:node,createTextNode:value=>node('#text',value)}, performance:{now:()=>now},
    setInterval(fn){tick=fn;return 1},clearInterval(){tick=null},
    setTimeout(fn){finish=fn;return 2},clearTimeout(){finish=null}};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/search-ui.js'),'utf8'),context);
  return {ui:context.window.PccSearchUI,node,advance(ms){now=ms;tick?.()}, cleanup(){finish?.()}};
}
test('estimated progress stops short of completion, completes on success, and clears on error',()=>{
  const b=setup(), nodes={};
  const host={querySelector(k){return nodes[k] ||= b.node()},replaceChildren(){this.cleared=true}};
  const p=b.ui.progress(host);p.start();
  assert.equal(host.querySelector('[data-lance-marker]').style.transition,'left 180ms linear');
  assert.ok(host.innerHTML.includes('height:36px'));
  assert.ok(host.innerHTML.includes('height:5px'));
  b.advance(6000);
  assert.equal(host.querySelector('[data-lance-marker]').style.left,'90%');
  assert.equal(host.querySelector('[data-lance-fill]').style.width,'90%');
  b.advance(60000);
  assert.ok(parseFloat(host.querySelector('[data-lance-marker]').style.left) < 95);
  assert.ok(parseFloat(host.querySelector('[data-lance-marker]').style.left) > 94);
  assert.equal(host.querySelector('[data-progress-note]').textContent,'Taking a little longer…');
  p.finish(true);assert.equal(host.querySelector('[data-lance-marker]').style.left,'100%');
  b.cleanup();assert.equal(host.cleared,true);
  host.cleared=false;p.start();p.finish(false);assert.equal(host.cleared,true);
});
test('long answers expand and collapse without losing original content; short answers stay visible',()=>{
  const b=setup(), body=b.node();body.id='answer';body.textContent='A long answer with a link. '.repeat(40);
  body.parentElement={querySelectorAll:()=>[]};
  body.before=n=>body.preview=n;body.after=n=>body.button=n;
  b.ui.answer(body);
  assert.equal(body.hidden,true);assert.equal(body.button.getAttribute('aria-expanded'),'false');
  assert.ok(body.preview.textContent.length<=451);
  body.button.click();assert.equal(body.hidden,false);assert.equal(body.preview.hidden,true);
  body.button.click();assert.equal(body.hidden,true);
  body.textContent='Short answer';b.ui.answer(body);assert.equal(body.hidden,false);
});

test('duplicate HTML and Markdown help footers become a single canonical footer',()=>{
  const b=setup();
  for (const footer of [
    '<hr class="my-2" /><strong>Need additional help?</strong> Visit Help Centers & Support',
    '**Need additional help?** [Visit Help Centers & Support](https://pasadena.edu/student-services/get-help/index.php)',
    '<hr class="my-2"><strong>Need additional help?</strong> Help'
  ]) {
    const split=b.ui.splitAnswer('The answer.\n'+footer+'\n'+footer);
    assert.equal(split.bodyRaw,'The answer.');
    assert.equal((split.helpRaw.match(/Need additional help/g)||[]).length,1);
  }
  assert.equal(b.ui.splitAnswer('An answer without a footer.').bodyRaw,'An answer without a footer.');
});
test('references, help and feedback appear only after expansion, including short answers',()=>{
  for (const content of ['Short answer.', 'Long text. '.repeat(70)]) {
    const b=setup(),body=b.node(),refs=b.node(),help=b.node(),feedback=b.node();
    body.id='answer';refs.id='refs';help.id='help';feedback.id='feedback';
    body.textContent=content;
    body.parentElement={querySelectorAll:()=>[]};body.before=n=>body.preview=n;
    body.after=n=>body.next=n;
    b.ui.answer(body,refs,help,feedback);
    assert.equal(body.next,refs);
    assert.equal(refs.next,feedback);
    const button=feedback.next;
    assert.equal(button.next,help);
    assert.equal(button.className,'btn btn-yellow btn-sm py-1 px-2 rounded');
    for (const extra of [refs,help,feedback]) assert.equal(extra.hidden,true);
    button.click();
    for (const extra of [refs,help,feedback]) assert.equal(extra.hidden,false);
    button.click();
    for (const extra of [refs,help,feedback]) assert.equal(extra.hidden,true);
    if (content.length < 450) assert.equal(body.preview.textContent,content);
  }
});

test('preview preserves paragraph and inline markup while truncating text safely',()=>{
  const b=setup(),body=b.node(),first=b.node('p'),second=b.node('p'),link=b.node('a');
  first.textContent='First paragraph.';
  second.textContent='Second paragraph with ';
  link.textContent='a linked source '.repeat(50);link.setAttribute('href','https://pasadena.edu/');link.setAttribute('id','original-link');
  second.appendChild(link);body.appendChild(first);body.appendChild(second);
  const preview=b.ui.preview(body);
  assert.equal(preview.childNodes.length,2);
  assert.equal(preview.childNodes[0].tagName,'p');
  assert.equal(preview.childNodes[0].textContent,'First paragraph.');
  const copiedLink=preview.childNodes[1].childNodes[1];
  assert.equal(copiedLink.tagName,'a');
  assert.equal(copiedLink.getAttribute('href'),'https://pasadena.edu/');
  assert.equal(copiedLink.getAttribute('id'),undefined);
  assert.ok(preview.textContent.endsWith('…'));
  assert.ok(preview.textContent.length<=451);
  assert.ok(body.textContent.length>450);
});

test('preview fades the bottom 35 percent and expand/collapse animates the container height',()=>{
  const b=setup(),body=b.node(),animations=[];
  body.id='answer';body.textContent='Long paragraph. '.repeat(60);
  body.parentElement={querySelectorAll:()=>[],
    getBoundingClientRect:()=>({height:body.hidden ? 160 : 500}),
    animate(frames,options){const animation={frames,options,cancel(){this.cancelled=true}};animations.push(animation);return animation}};
  body.before=n=>body.preview=n;body.after=n=>body.button=n;
  b.ui.answer(body);
  const fade=body.preview.childNodes.find(n=>n.dataset.answerFade==='1');
  assert.ok(fade.style.cssText.includes('inset:65% 0 0'));
  assert.ok(fade.style.cssText.includes('rgba(255,255,255,0),#fff'));
  assert.ok(!fade.style.cssText.includes('blur'));
  assert.equal(fade.getAttribute('aria-hidden'),'true');
  body.button.click();
  assert.equal(animations[0].frames[0].height,'160px');
  assert.equal(animations[0].frames[1].height,'500px');
  assert.equal(animations[0].options.duration,320);
  assert.equal(body.preview.hidden,true);
  body.button.click();
  assert.equal(animations[0].cancelled,true);
  assert.equal(animations[1].frames[1].height,'160px');
});

test('external result icons distinguish PCC hostnames from other sites',()=>{
  const {ui}=setup();
  for (const url of ['https://pasadena.edu/a','https://www.pasadena.edu/a','/a','javascript:alert(1)']) {
    assert.equal(ui.externalResultIcon(url),'');
  }
  for (const url of ['https://example.com/a','https://pasadena.edu.example.com/a']) {
    assert.ok(ui.externalResultIcon(url).includes('fa-arrow-up-right-from-square'));
  }
});
