(() => {
	const wireUpEvent = (element, link, observer) => {
		if (element.dataset.hackApplied) return;

		element.dataset.hackApplied = "true";

		const clonedElement = element.cloneNode(true);
		element.replaceWith(clonedElement);

		clonedElement.addEventListener("click", () => {
			observer.disconnect();
			location.href = link;
		});
	};

	const observer = new MutationObserver(() => {
		const termlyElement = document.getElementById("termly-code-snippet-support");

		if (!termlyElement) return;

		observer.disconnect();

		const innerObserver = new MutationObserver(() => {
			const policyElements = Array.from(
				document.querySelectorAll("span[class^='termly-styles-root-']"),
			);

			const cookiePolicyElements = policyElements.filter(
				(element) => element.textContent === "Cookie Policy",
			);
			const privacyPolicyElements = policyElements.filter(
				(element) => element.textContent === "Privacy Policy",
			);

			cookiePolicyElements.forEach((element) =>
				wireUpEvent(element, "/legal/cookie-policy", innerObserver),
			);
			privacyPolicyElements.forEach((element) =>
				wireUpEvent(element, "/legal/privacy-policy", innerObserver),
			);
		});

		innerObserver.observe(termlyElement, {
			childList: true,
			subtree: true,
		});
	});

	observer.observe(document.body, {
		childList: true,
		subtree: true,
	});
})();
