// Interactive tutorial enhancements
document.addEventListener('DOMContentLoaded', function() {
  // Add copy buttons to regex patterns in text
  const codeBlocks = document.querySelectorAll('.page-main pre code');
  codeBlocks.forEach((block) => {
    if (block.textContent.includes('/') && block.textContent.match(/\/.*\//)) {
      const pre = block.parentElement;
      if (!pre.querySelector('.regex-copy')) {
        const btn = document.createElement('button');
        btn.className = 'regex-copy';
        btn.textContent = 'Copy pattern';
        btn.style.cssText = 'position:absolute;top:0.5rem;right:0.5rem;padding:0.25rem 0.5rem;font-size:0.75rem;cursor:pointer;';
        pre.style.position = 'relative';
        pre.appendChild(btn);
        btn.addEventListener('click', () => {
          const match = block.textContent.match(/\/[^\/\n]+\/[a-z]*/);
          if (match) {
            navigator.clipboard.writeText(match[0]);
            btn.textContent = 'Copied!';
            setTimeout(() => btn.textContent = 'Copy pattern', 1500);
          }
        });
      }
    }
  });

  // Animate regex flow diagrams
  const flowNodes = document.querySelectorAll('.regex-node');
  flowNodes.forEach((node, i) => {
    node.style.animationDelay = (i * 0.1) + 's';
  });
});
