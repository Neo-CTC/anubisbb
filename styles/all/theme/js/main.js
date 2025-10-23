import processFast from "./proof-of-work.js";
import processSlow from "./proof-of-work-slow.js";
import { testVideo } from "./video.js";

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

const dependencies = [
  {
    name: "WebCrypto",
    msg: "Your browser doesn't have a functioning web.crypto element. Are you viewing this over a secure context?",
    value: window.crypto,
  },
  {
    name: "Web Workers",
    msg: "Your browser doesn't support web workers (Anubis uses this to avoid freezing your browser). Do you have a plugin like JShelter installed?",
    value: window.Worker,
  },
];

(async () => {
  const status = document.getElementById('status');
  const image = document.getElementById('image');
  const title = document.getElementById('title');
  const progress = document.getElementById('progress');

  const anubis_settings = JSON.parse(document.getElementById('anubis_settings').textContent)
  const anubisVersion = anubis_settings['version'];
  const staticPrefix = anubis_settings['static_prefix'];
  const passRoute = anubis_settings['route_prefix'];
  const rootPath = anubis_settings['root_path'];

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

  if (!window.isSecureContext) {
    ohNoes({
      titleMsg: "Your context is not secure!",
      statusMsg: `Try connecting over HTTPS or let the admin know to set up HTTPS. For more information, see <a href="https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts#when_is_a_context_considered_secure">MDN</a>.`,
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

  status.innerHTML = 'Calculating...';

  for (const { value, name, msg } of dependencies) {
    if (!value) {
      ohNoes({
        titleMsg: `Missing feature ${name}`,
        statusMsg: msg,
        imageSrc: imageURL("reject", anubisVersion, staticPrefix),
      });
    }
  }

  const { challenge, timestamp, rules } = JSON.parse(document.getElementById('challenge').textContent);

  const process = algorithms[rules.algorithm];
  if (!process) {
    ohNoes({
      titleMsg: "Challenge error!",
      statusMsg: `Failed to resolve check algorithm. You may want to reload the page.`,
      imageSrc: imageURL("reject", anubisVersion, staticPrefix),
    });
    return;
  }

  status.innerHTML = `Calculating...<br/>Difficulty: ${rules.report_as}, `;
  progress.style.display = "inline-block";

  // the whole text, including "Speed:", as a single node, because some browsers
  // (Firefox mobile) present screen readers with each node as a separate piece
  // of text.
  const rateText = document.createTextNode("Speed: 0kH/s");
  status.appendChild(rateText);

  let lastSpeedUpdate = 0;
  let showingApology = false;
  const likelihood = Math.pow(16, -rules.report_as);


  let attempt_count = parseInt(sessionStorage.getItem('anubis_attempts'))
  if (isNaN(attempt_count))
  {
    attempt_count = 0;
  }
  console.log(attempt_count)
  if (attempt_count >= 3)
  {
    ohNoes({
      titleMsg: "Oh noes!",
      statusMsg: `Unable to pass the challenge after ${attempt_count} attempts.<br><a href="" onclick="sessionStorage.setItem('anubis_attempts','0')">Retry</a>, <a href="${rootPath}ucp.php?mode=login">login</a>, or <a href="${rootPath}memberlist.php?mode=contactadmin">contact the administrators</a> for help.`,
      imageSrc: imageURL("reject", anubisVersion, staticPrefix),
    });
    return;
  }


  try {
    const t0 = Date.now();
    const { hash, nonce } = await process(
      challenge,
      rules.difficulty,
      null,
      (iters) => {
        const delta = Date.now() - t0;
        // only update the speed every second so it's less visually distracting
        if (delta - lastSpeedUpdate > 1000) {
          lastSpeedUpdate = delta;
          rateText.data = `Speed: ${(iters / delta).toFixed(3)}kH/s`;
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
              "Verification is taking longer than expected. Please do not refresh the page.",
            ),
          );
          showingApology = true;
        }
      },
    );
    const t1 = Date.now();
    console.log({ hash, nonce });

    title.innerHTML = "Success!";
    attempt_count += 1
    sessionStorage.setItem('anubis_attempts',attempt_count.toString())

    const url_here = new URL(window.location.href)
    let redir;
    if (url_here.searchParams.get('redir')) {
      redir = url_here.searchParams.get('redir')
    } else {
      redir = window.location.href;
    }
    const goto = u(passRoute, {
          response: hash,
          nonce,
          redir,
          timestamp,
          elapsedTime: t1 - t0
        })

    status.innerHTML = `Done! Took ${t1 - t0}ms, ${nonce} iterations`;
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
      container.innerHTML = "I've finished reading, continue →";

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
      fc.innerHTML = `<a href="${goto}" style="color: inherit; background-color: unset; flex: 1; align-content: center;font-weight: bold">Continue ➞</a>`;

      setTimeout(() => {
        window.location.assign(goto);
      }, 1000);
    }

  } catch (err) {
    ohNoes({
      titleMsg: "Calculation error!",
      statusMsg: `Failed to calculate challenge: ${err.message}`,
      imageSrc: imageURL("reject", anubisVersion, staticPrefix),
    });
  }
})();
