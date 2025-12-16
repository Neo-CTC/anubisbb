import processFast from "./proof-of-work.js";
import processSlow from "./proof-of-work-slow.js";
import {testVideo} from "./video.js";

const abort_controller = new AbortController();
globalThis.anubis_abort = abort_controller

const algorithms = {
  "fast": processFast,
  "slow": processSlow,
};

// from Xeact
const u = (url = "", params = {}) => {
  let result = new URL(url, window.location.href);
  Object.entries(params).forEach(([k, v]) => result.searchParams.set(k, v));
  return result.toString();
};

const imageURL = (mood, cacheBuster, staticPrefix) =>
  u(`${staticPrefix}img/${mood}.webp`, { cacheBuster });



(async () => {
  const anubis_settings = JSON.parse(document.getElementById('anubis_settings').textContent)

  const status = document.getElementById('status');
  const image = document.getElementById('image');
  const title = document.getElementById('title');
  const progress = document.getElementById('progress');

  const anubisVersion = anubis_settings['version'];
  const staticPrefix = anubis_settings['static_prefix'];
  const passRoute = anubis_settings['routes']['pass'];
  const loginPath = anubis_settings['routes']['login'];
  const contactPath = anubis_settings['routes']['contact'];

  const details = document.querySelector('details');
  let userReadDetails = false;

  if (details) {
    details.addEventListener("toggle", () => {
      if (details.open) {
        userReadDetails = true;
      }
    });
  }

  const ohNoes = ({ titleMsg, statusMsg, imageSrc }) => {
    title.innerHTML = titleMsg;
    status.innerHTML = statusMsg;
    image.src = imageSrc;
    progress.style.display = "none";
  };

  const fetch_lang = async (lang) => {
    try {
      const f = await fetch(`${anubis_settings['static_prefix']}/language/${lang}/strings.json`);
      if (f.status !== 200 || f.headers.get('Content-Type') !== 'application/json'){
        console.warn('Bad response for language file', f)
        return false;
      }
      return await f.json();
    } catch (e) {
      return false;
    }
  }

  // Loop until we find a working language
  let lang_strings = null
  for (const lang of [anubis_settings['user_lang'],'en']) {
    const ls = await fetch_lang(lang);
    if (ls !== false) {
      lang_strings = ls;
      break;
    }
  }
  // Still no language?
  if (lang_strings === null) {
    ohNoes({
      titleMsg: "Missing language file",
      statusMsg: "Could not load language file",
      imageSrc: imageURL("reject", anubisVersion, staticPrefix),
    });
    return;
  }

  const lang = (base_str, ...values) => {
    let str = lang_strings[base_str]
    if (typeof str === 'undefined') {
      return base_str
    }
    return str.replace(/{(\d+)}/g, function(match, index) {
      return typeof values[index] !== 'undefined' ? values[index] : match;
    });
  }

  if (!window.isSecureContext) {
    ohNoes({
      titleMsg: lang('not_secure_title'),
      statusMsg: lang('not_secure'),
      imageSrc: imageURL("reject", anubisVersion, staticPrefix),
    });
    return;
  }

  // const testarea = document.getElementById('testarea');

  // const videoWorks = await testVideo(testarea);
  // console.log(`videoWorks: ${videoWorks}`);

  // if (!videoWorks) {
  //   title.innerHTML = "Oh no!";
  //   status.innerHTML = "Checks failed. Please check your browser's settings and try again.";
  //   image.src = imageURL("reject");
  //   progress.style.display = "none";
  //   return;
  // }

  status.innerHTML = lang('calculating');

  const dependencies = [
    {
      name: "WebCrypto",
      msg: lang('missing_crypto'),
      value: window.crypto,
    },
    {
      name: "Web Workers",
      msg: lang('missing_workers'),
      value: window.Worker,
    },
  ];
  for (const { value, name, msg } of dependencies) {
    if (!value) {
      ohNoes({
        titleMsg: lang('missing_dependency_title', name),
        statusMsg: msg,
        imageSrc: imageURL("reject", anubisVersion, staticPrefix),
      });
      return;
    }
  }

  const { challenge, timestamp, rules } = JSON.parse(document.getElementById('challenge').textContent);

  const process = algorithms[rules.algorithm];
  if (!process) {
    ohNoes({
      titleMsg: lang('challenge_error_title'),
      statusMsg: lang('process_error'),
      imageSrc: imageURL("reject", anubisVersion, staticPrefix),
    });
    return;
  }

  status.innerHTML = lang('status', rules.report_as);
  progress.style.display = "inline-block";

  // the whole text, including "Speed:", as a single node, because some browsers
  // (Firefox mobile) present screen readers with each node as a separate piece
  // of text.
  const rateText = document.createTextNode(lang('rate', '0'));
  status.appendChild(rateText);

  let lastSpeedUpdate = 0;
  let showingApology = false;
  const likelihood = Math.pow(16, -rules.report_as);

  let attempt_count = 0;
  try {
    let c = document.cookie

    // Should have received a cookie from the api
    if (c.length === 0) {
        ohNoes({
          titleMsg: lang('general_error_title'),
          statusMsg: lang('cookies_disabled'),
          imageSrc: imageURL("reject", anubisVersion, staticPrefix),
        });
        return;
    }

    attempt_count = parseInt(sessionStorage.getItem('anubis_attempts'))
    if (isNaN(attempt_count)) {
      attempt_count = 0;
    }
    if (attempt_count >= 3) {
      ohNoes({
        titleMsg: lang('general_error_title'),
        statusMsg: lang('attempt_limit', attempt_count, loginPath, contactPath),
        imageSrc: imageURL("reject", anubisVersion, staticPrefix),
      });
      return;
    }
  }catch (err) {
    console.error(err);

    // Can't access session storage
    if (err.name === 'SecurityError') {
      ohNoes({
        titleMsg: lang('challenge_error_title'),
        statusMsg: lang('security_error'),
        imageSrc: imageURL("reject", anubisVersion, staticPrefix),
      });
    }
    else{
      ohNoes({
        titleMsg: lang('calculation_error_title'),
        statusMsg: lang('calculation_error', err.message),
        imageSrc: imageURL("reject", anubisVersion, staticPrefix),
      });
    }
    return;
  }

  try {
    const t0 = Date.now();
    const { hash, nonce } = await process(
      challenge,
      rules.difficulty,
      abort_controller.signal,
      (iters) => {
        const delta = Date.now() - t0;
        // only update the speed every second so it's less visually distracting
        if (delta - lastSpeedUpdate > 1000) {
          lastSpeedUpdate = delta;
          rateText.data = lang('rate', (iters / delta).toFixed(3));
        }
        // the probability of still being on the page is (1 - likelihood) ^ iters.
        // by definition, half of the time the progress bar only gets to half, so
        // apply a polynomial ease-out function to move faster in the beginning
        // and then slow down as things get increasingly unlikely. quadratic felt
        // the best in testing, but this may need adjustment in the future.

        const probability = Math.pow(1 - likelihood, iters);
        const distance = (1 - Math.pow(probability, 2)) * 100;
        progress["aria-valuenow"] = distance;
        progress.firstElementChild.style.width = `${distance}%`;

        if (probability < 0.1 && !showingApology) {
          status.append(
            document.createElement("br"),
            document.createTextNode(
                lang('verification_time'),
            ),
          );
          showingApology = true;
        }
      },
    );
    const t1 = Date.now();
    console.log({ hash, nonce });

    title.innerHTML = lang('success');
    attempt_count += 1
    sessionStorage.setItem('anubis_attempts',attempt_count.toString())

    const url_here = new URL(window.location.href)
    let redir;
    if (url_here.searchParams.get('redir')) {
      redir = url_here.searchParams.get('redir')
    } else {
      redir = '';
    }
    const goto = u(passRoute, {
          response: hash,
          nonce,
          redir,
          timestamp,
          elapsedTime: t1 - t0
        })

    status.innerHTML = lang('finished', (t1 - t0), nonce);
    image.src = imageURL("happy", anubisVersion, staticPrefix);

    if (userReadDetails) {
      const container = document.getElementById("progress");

      // Style progress bar as a continue button
      container.style.display = "flex";
      container.style.alignItems = "center";
      container.style.justifyContent = "center";
      container.style.height = "2rem";
      container.style.borderRadius = "1rem";
      container.style.cursor = "pointer";
      container.style.background = "#b16286";
      container.style.color = "white";
      container.style.fontWeight = "bold";
      container.style.outline = "4px solid #b16286";
      container.style.outlineOffset = "2px";
      container.style.width = "min(20rem, 90%)";
      container.style.margin = "1rem auto 2rem";
      container.innerHTML = lang('finished_reading');

      function onDetailsExpand() {
        window.location.assign(goto,);
      }

      container.onclick = onDetailsExpand;
      setTimeout(onDetailsExpand, 30000);

    } else {

      let fc = progress.firstElementChild
      fc.style.width = '100%';
      fc.style.color = '#f9f5d7';
      fc.style.display = 'flex';
      fc.innerHTML = lang('finished_progress_bar', goto);

      setTimeout(() => {
        window.location.assign(goto);
      }, 1000);
    }

  } catch (err) {
    ohNoes({
      titleMsg: lang('calculation_error_title'),
      statusMsg: lang('calculation_error', err.message),
      imageSrc: imageURL("reject", anubisVersion, staticPrefix),
    });
  }
})();
