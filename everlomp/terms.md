# Terms of Use, Disclaimer and Limitation of Liability

**Effective date:** 2026-08-16

These Terms of Use, Disclaimer and Limitation of Liability (“Terms”) apply to the software, scripts, source code, Docker images, configuration files, interfaces, modifications, integrations, documentation and related components made available as part of **everlomp** (collectively, the “Software”).

By installing, accessing, copying, modifying, distributing or otherwise using the Software, you acknowledge that you have read and understood these Terms and agree to them to the maximum extent permitted by applicable law.

If you do not agree with these Terms, do not use the Software.

## 1. Software Provided “As Is”

The Software is provided **“as is” and “as available”**, without warranties, representations, guarantees or promises of any kind.

To the maximum extent permitted by applicable law, the creator, authors, contributors and distributors of the Software expressly disclaim all warranties, whether express, implied or statutory, including but not limited to warranties relating to:

* merchantability;
* fitness for a particular purpose;
* satisfactory quality;
* reliability;
* availability;
* compatibility;
* security;
* accuracy;
* performance;
* non-infringement;
* error-free operation;
* uninterrupted operation;
* preservation or recovery of data; and
* suitability for backup, disaster recovery or long-term data storage.

Use of the Software is entirely at your own risk.

## 2. No Guarantee of Data Preservation

The Software must **not be considered a guarantee that your data, backups, snapshots, repositories, configurations or other information will remain available or recoverable**.

Instances, containers, virtual machines, storage volumes, repositories, accounts, services or other resources associated with the Software may be stopped, reset, corrupted, deleted, replaced, removed or otherwise become unavailable at any time.

This may occur with or without prior notice.

You are solely responsible for ensuring that your data is backed up appropriately.

You should maintain independent copies of important data in locations that do not depend on the Software, the same server, the same storage provider, or the same infrastructure.

The creator is not responsible for:

* deleted data;
* corrupted data;
* missing data;
* inaccessible data;
* failed backups;
* incomplete backups;
* failed restores;
* corrupted backup repositories;
* lost encryption keys;
* lost passwords or credentials;
* deleted instances;
* deleted containers or volumes;
* hardware failure;
* filesystem failure;
* storage-provider failure;
* backup-provider failure; or
* any other loss of data, regardless of cause.

You are responsible for regularly verifying that your backups exist, are complete and can actually be restored.

**A backup that has not been independently verified and tested for restoration should not be assumed to be recoverable.**

## 3. Instances May Be Removed

Any instance, installation, environment or service made available in connection with the Software may be suspended, disabled, reset or permanently removed at any time.

Unless a separate written agreement expressly states otherwise, there is:

* no guaranteed retention period;
* no guaranteed uptime;
* no service-level agreement;
* no guaranteed notice before removal;
* no obligation to preserve an instance;
* no obligation to preserve configuration;
* no obligation to preserve backups; and
* no obligation to provide recovery assistance.

You are responsible for exporting or copying anything you wish to retain before it is removed.

## 4. Security Is the User’s Responsibility

No representation or warranty is made that the Software is secure.

The Software may contain known or unknown:

* vulnerabilities;
* bugs;
* configuration errors;
* insecure defaults;
* dependency vulnerabilities;
* authentication weaknesses;
* authorization weaknesses;
* privilege-escalation vulnerabilities;
* remote-code-execution vulnerabilities;
* information-disclosure vulnerabilities; or
* other security defects.

The Software may not have undergone a professional security audit, penetration test, formal code review or independent security assessment.

You are solely responsible for determining whether the Software is sufficiently secure for your intended use.

Before exposing the Software to the Internet, a public network, untrusted users or sensitive data, you should independently review and secure the entire deployment.

## 5. User Must Review the Code

The Software is made available on the basis that users are responsible for reviewing and understanding it before relying on it.

You are responsible for reviewing:

* source code;
* scripts;
* Dockerfiles;
* container images;
* dependencies;
* reverse-proxy configuration;
* authentication configuration;
* filesystem permissions;
* network exposure;
* firewall rules;
* storage configuration;
* backup configuration;
* encryption configuration; and
* any other component relevant to your deployment.

The existence of source code, documentation, installation scripts or default configuration does not constitute a representation that the Software is secure, correct or appropriate for your environment.

If you are not capable of evaluating the security or suitability of the Software yourself, you are responsible for obtaining assistance from someone who can.

## 6. Everlomp and Other Unmaintained Software

The Software may use, modify, interact with, incorporate or depend upon **Everlomp** and other third-party projects.

Everlomp may be unmaintained, minimally maintained, abandoned or outdated.

It may contain outdated dependencies, unresolved bugs, security vulnerabilities or components that are no longer supported by their original developers.

The inclusion, modification or use of Everlomp does not constitute a representation that Everlomp is secure, maintained, supported or suitable for production use.

You expressly accept all risks associated with running outdated, unsupported, abandoned or unmaintained software.

## 7. Third-Party Software

The Software may depend upon or interact with third-party software, including but not limited to operating systems, container runtimes, web servers, backup software, databases, libraries, package managers, storage providers and networking software.

Such third-party software is controlled by its respective developers and is subject to its own licenses, terms, security practices and limitations.

The creator does not control and is not responsible for third-party software.

The creator is not responsible for losses caused by:

* third-party vulnerabilities;
* third-party updates;
* breaking changes;
* discontinued software;
* discontinued APIs;
* dependency changes;
* malicious packages;
* supply-chain attacks;
* compromised repositories;
* third-party outages; or
* incompatibilities introduced by another project.

References to or integration with another project do not constitute endorsement of that project.

## 8. No Maintenance or Support Obligation

Unless separately agreed in writing, the creator has no obligation to:

* maintain the Software;
* continue development;
* fix bugs;
* patch vulnerabilities;
* respond to security reports;
* answer questions;
* provide technical support;
* provide updates;
* provide compatibility updates;
* maintain documentation;
* maintain repositories;
* maintain download links; or
* continue making the Software available.

Development may stop permanently at any time.

A published release should not be interpreted as a promise that another release will ever be provided.

## 9. Updates May Break Existing Installations

Updates, patches, modifications or new releases may introduce breaking changes.

Configuration formats, APIs, scripts, paths, authentication mechanisms, storage formats, networking behavior and dependencies may change without notice.

You are responsible for testing updates before deploying them to systems containing important data.

The creator is not responsible for damage caused by installing, failing to install, upgrading, downgrading or otherwise applying any version of the Software.

## 10. No Guarantee of Backup or Disaster-Recovery Fitness

Although the Software may interact with backup software or provide backup-related functionality, it is not represented or warranted to constitute a complete backup or disaster-recovery solution.

You remain responsible for designing your own backup and disaster-recovery strategy.

For important data, you should use multiple independent copies and avoid relying on a single server, repository, storage provider, application or administrator account.

You are responsible for retaining any passwords, recovery information, encryption keys and credentials required to restore your data.

The creator cannot recover encrypted data where required keys or credentials have been lost.

## 11. Credentials and Access Control

You are solely responsible for:

* choosing secure passwords;
* securing authentication credentials;
* protecting API keys;
* protecting encryption keys;
* restricting administrative access;
* configuring multi-factor authentication where available;
* securing SSH access;
* configuring network access; and
* preventing unauthorized access to your installation.

The creator is not responsible if an attacker, unauthorized person or malicious software gains access to your installation, backups, credentials or data.

## 12. Internet Exposure

You acknowledge that exposing software to the public Internet involves security risks.

You are responsible for determining whether the Software should be publicly accessible.

You are also responsible for properly configuring:

* TLS/HTTPS;
* certificates;
* reverse proxies;
* authentication;
* firewalls;
* rate limiting;
* network isolation;
* operating-system security;
* container security; and
* access restrictions.

Default configurations and examples are provided for convenience only and should not be assumed to represent secure production configurations.

## 13. Not Intended for Critical Systems

Unless expressly agreed otherwise in writing, the Software is not designed, certified or intended for systems where failure could cause significant harm.

You should not rely upon the Software as the sole protection mechanism for:

* irreplaceable data;
* safety-critical systems;
* medical systems;
* emergency systems;
* financial infrastructure;
* life-support systems;
* industrial control systems;
* national-security systems; or
* any environment where failure could cause death, personal injury, substantial financial loss or substantial property damage.

Any such use is entirely at your own risk.

## 14. User Responsibility for Compliance

You are responsible for ensuring that your use of the Software complies with all laws, regulations, contractual obligations, licenses and policies applicable to you.

This includes responsibility for determining whether you are legally entitled to store, process, copy, transmit or back up the data you place into the Software.

The creator does not provide legal, regulatory, compliance, cybersecurity or data-protection advice.

## 15. Privacy and Sensitive Information

You are responsible for determining whether the Software is appropriate for storing or processing personal data, confidential information, business information, credentials or other sensitive material.

No representation is made that the Software complies with any particular privacy, cybersecurity, data-protection or industry-specific regulatory framework.

You are responsible for implementing any safeguards required for your own use case.

## 16. No Liability for Security Incidents

To the maximum extent permitted by applicable law, the creator, authors, contributors, maintainers and distributors shall not be liable for any loss or damage arising from or relating to:

* hacking;
* unauthorized access;
* malware;
* ransomware;
* credential theft;
* data breaches;
* privilege escalation;
* denial-of-service attacks;
* software vulnerabilities;
* compromised dependencies;
* malicious third-party software;
* supply-chain attacks;
* misconfiguration;
* exposed services; or
* any other cybersecurity incident.

This applies whether a vulnerability was known or unknown at the time the Software was made available.

## 17. Limitation of Liability

**To the maximum extent permitted by applicable law, the creator, authors, contributors, maintainers and distributors of the Software shall not be liable for any claims, losses, damages, costs or liabilities arising from or related to the Software or its use.**

This includes, without limitation:

* loss of data;
* loss of backups;
* loss of revenue;
* loss of profit;
* loss of business;
* loss of opportunity;
* loss of reputation;
* loss of goodwill;
* service interruption;
* downtime;
* security incidents;
* privacy incidents;
* hardware damage;
* software damage;
* restoration costs;
* replacement-service costs; and
* any direct, indirect, incidental, special, exemplary, punitive or consequential damages.

This limitation applies regardless of the legal theory asserted, including contract, tort, negligence, strict liability or otherwise, and even if the possibility of such damage was known or foreseeable.

Nothing in these Terms excludes or limits liability that cannot legally be excluded or limited under applicable law.

## 18. Assumption of Risk

By using the Software, you acknowledge that software can fail and that security protections can be bypassed.

You voluntarily assume the risks associated with:

* installation;
* operation;
* modification;
* Internet exposure;
* data storage;
* backup creation;
* backup restoration;
* system administration;
* third-party dependencies; and
* continued use of outdated software.

You are responsible for deciding whether those risks are acceptable.

## 19. User Modifications

You may modify the Software at your own risk.

The creator is not responsible for any consequences resulting from modifications made by you or by third parties.

Once modified, the resulting software should not be assumed to behave in the same way as the original Software.

## 20. Forks and Third-Party Distributions

Third parties may create forks, modified versions, packages, containers or distributions based upon the Software where permitted by the applicable software license.

Unless expressly stated otherwise, such forks and distributions are independent projects.

The original creator does not control and is not responsible for:

* modifications made by third parties;
* security vulnerabilities introduced by forks;
* malware introduced into forks;
* representations made by third-party distributors;
* support offered by third parties; or
* damage caused by modified versions.

The existence of a fork does not imply endorsement, approval or review by the original creator.

## 21. Open-Source Permission

The source code should be distributed under the separate license file included with the project.

Where the project is released under the MIT License, users may use, copy, modify, merge, publish, distribute, sublicense and otherwise deal in the Software subject to the conditions of that license.

The applicable `LICENSE` file controls the copyright permissions granted for the Software.

These Terms are intended primarily to describe the risks, responsibilities and conditions associated with use of the Software and do not reduce permissions granted under an applicable open-source license.

## 22. No Partnership, Agency or Fiduciary Relationship

Use of the Software does not create a partnership, joint venture, employment relationship, agency relationship, fiduciary relationship or professional-client relationship between you and the creator.

The creator does not become responsible for your systems, infrastructure, backups, cybersecurity or data merely because you use the Software.

## 23. No Reliance

You should not make important operational, financial, security or data-retention decisions solely in reliance upon statements contained in documentation, examples, source-code comments, issue discussions, release notes or other material relating to the Software.

You are responsible for independently verifying anything that is important to your use case.

## 24. Documentation and Examples

Documentation, example configurations, scripts and instructions may contain mistakes or become outdated.

They are provided for informational and convenience purposes only.

You are responsible for validating commands and configurations before executing them, particularly commands that could modify or delete data.

## 25. Deletion and Destructive Operations

Some functionality may modify, overwrite, prune, expire or permanently delete data.

You are responsible for understanding the effect of an operation before performing it.

The creator is not responsible for accidental deletion caused by:

* user error;
* configuration;
* retention policies;
* automated cleanup;
* scheduled jobs;
* repository maintenance;
* software bugs; or
* misunderstood functionality.

## 26. No Obligation to Recover Data

The creator has no obligation to attempt data recovery, repository repair, password recovery, instance restoration or forensic investigation following a failure.

Any assistance voluntarily provided does not create an ongoing obligation to provide support or recovery services.

## 27. Indemnification

To the extent permitted by applicable law, you agree to defend, indemnify and hold harmless the creator, authors, contributors and distributors from third-party claims, liabilities, damages and reasonable costs arising from your unlawful use of the Software, your distribution of a modified version, your violation of another person's rights, or content and data that you choose to process using the Software.

This provision applies only to the extent enforceable under applicable law.

## 28. Changes to the Software

The Software may be changed, redesigned, restricted, discontinued or removed at any time.

Features may be added or removed without notice.

There is no promise of backward compatibility or continued availability.

## 29. Changes to These Terms

These Terms may be updated from time to time.

A revised version may apply to future downloads, installations, updates or use of hosted services after the revised Terms become effective, subject to applicable law.

The effective date displayed at the beginning of the Terms identifies the current version.

## 30. Severability

If any provision of these Terms is found to be invalid, illegal or unenforceable, that provision shall be interpreted or limited to the minimum extent necessary where legally permitted, and the remaining provisions shall continue in effect.

## 31. No Waiver

Failure to enforce any provision of these Terms does not constitute a waiver of that provision or of the right to enforce it later.

## 32. Entire Agreement

Unless another written agreement expressly applies, these Terms together with the applicable software license constitute the agreement concerning use of the Software.

If there is a conflict between these Terms and an applicable open-source license concerning copyright permissions to copy, modify or distribute the Software, the open-source license controls those copyright permissions.

## 33. Mandatory Legal Rights

Nothing in these Terms is intended to exclude, restrict or waive any right or liability that cannot lawfully be excluded, restricted or waived.

Where applicable law gives a user mandatory rights that conflict with these Terms, those mandatory rights prevail only to the extent required by law.

## 34. Acknowledgment

By using the Software, you acknowledge and understand that:

**YOU ARE RESPONSIBLE FOR YOUR OWN DATA.**

**YOU ARE RESPONSIBLE FOR YOUR OWN BACKUPS.**

**YOU ARE RESPONSIBLE FOR VERIFYING THAT YOUR BACKUPS CAN BE RESTORED.**

**YOU ARE RESPONSIBLE FOR SECURING YOUR OWN INSTALLATION.**

**THE SOFTWARE MAY CONTAIN VULNERABILITIES OR DEFECTS.**

**THE SOFTWARE MAY BE UNMAINTAINED OR DEPEND UPON UNMAINTAINED SOFTWARE.**

**YOUR INSTANCE OR DATA MAY BECOME UNAVAILABLE OR BE REMOVED.**

**THE SOFTWARE IS PROVIDED WITHOUT A GUARANTEE OF SECURITY, AVAILABILITY, DATA RETENTION OR FITNESS FOR ANY PARTICULAR PURPOSE.**

**TO THE MAXIMUM EXTENT PERMITTED BY LAW, YOU USE THE SOFTWARE ENTIRELY AT YOUR OWN RISK.**

## License

The original code created for this Project is licensed under the **MIT License**.

This means that, subject to the MIT License, anyone may use, copy, modify, merge, publish, distribute, sublicense and/or sell copies of the Project-authored software.

The Software is provided **“as is”**, without warranty of any kind. To the maximum extent permitted by applicable law, the authors and copyright holders are not liable for claims, damages or other liability arising from the Software or its use.

Third-party software included, modified or used by this Project remains subject to its own respective licenses and copyright terms. The MIT License for Project-authored code does not replace or override licenses that apply to third-party components.

A copy of the MIT License should be included with the Project in the `LICENSE` file.
