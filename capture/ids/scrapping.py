# #!/usr/local/bin/python

try:
    import logging
    FORMAT = "%(asctime)s;%(levelname)s;%(message)s"
    logging.basicConfig(filename='scrapping.log', level=logging.INFO, format=FORMAT)
    logger = logging.getLogger(__name__)
    import time
    import os
    import csv
    import json
    import sys
    import bs4
    import splinter
    import selenium
    executable_path = {'executable_path': ''}
except (ImportError, NameError) as e:
    os.remove('ids.tmp')
    logger.error('Unexpected error', exc_info=True)
    raise


username = ""
password = ""

def get_replies(browser):
    try:
        soup = bs4.BeautifulSoup(browser.html, 'lxml')
        if browser.is_element_present_by_css('div.replies-to'):
            return [i.get('data-item-id') for i in soup.select('div.replies-to div.tweet')]
        return []
    except selenium.common.exceptions.ElementNotVisibleException:
        f = open('error_urls.log', 'a+')
        f.write("{}\n".format(browser.url))
        f.close()
    except:
        raise


def get_likes(browser):
    try:
        time.sleep(1)
        if browser.is_element_present_by_css('a.request-favorited-popup'):
            browser.find_by_css('a.request-favorited-popup').first.click()
            time.sleep(1)
            soup = bs4.BeautifulSoup(browser.html, 'lxml')
            if browser.is_element_present_by_css('ol.activity-popup-users'):
                elements = soup.select('div#activity-popup-dialog ol.activity-popup-users li.js-stream-item')
                return [i.get('data-item-id') for i in elements]
        else:
            pass # TODO check compte non bloque
        return []
    except selenium.common.exceptions.ElementNotVisibleException:
        f = open('error_urls.log', 'a+')
        f.write("{}\n".format(browser.url))
        f.close()
    except:
        raise


if __name__ == "__main__":
    try:
        try:
            print("Start of scrapping.")
            logger.info('Start.')
            browser = splinter.Browser('phantomjs', **executable_path)
            browser.driver.set_window_size(800, 600)
            # authentication on Twitter
            browser.visit('http://twitter.com/login')
            browser.find_by_css('.signin-wrapper').find_by_name('session[username_or_email]').first.fill(username)
            browser.find_by_css('.signin-wrapper').find_by_name('session[password]').first.fill(password)
            browser.find_by_css('.submit')[1].click()
            # likes and replies
            ids_file = open('ids.tmp', 'r')
            results_file = open('ids.json', 'a+')
            f = csv.reader(ids_file, delimiter=',', quotechar='"')
            last_scrapped = 0
            for row in f:
                results = {}
                results[row[0]] = {}
                url = 'https://twitter.com/{}/status/{}'.format(row[1], row[0])
                browser.visit(url)
                logger.info('getting likes of {}'.format(row[0]))
                results[row[0]]['likes'] = get_likes(browser) # ids of users liking the tweet
                logger.info('getting replies of {}'.format(row[0]))
                results[row[0]]['replies'] = get_replies(browser) # ids of tweets replying to the tweet
                json.dump(results, results_file)
                results_file.write("\n")
                last_scrapped += 1
                print('.', end="", flush=True)
            # fermeture du programme
            ids_file.close()
            results_file.close()
            browser.quit()
            os.remove('ids.tmp')
            logger.info('End.')
            print('\nEnd of scrapping.')
        except selenium.common.exceptions.ElementNotVisibleException:
            f = open('error_page.html', 'w+')
            f.write(browser.html)
            f.close()
            logger.error('Elements not visible on the HTML page (see the file error_page.html).', exc_info=True)
            raise
        except FileNotFoundError:
            logger.error('No file ids.tmp to read.', exc_info=True)
            raise
        except KeyboardInterrupt:
            logger.error('Process stopped by user.', exc_info=True)
            raise
        except:
            logger.error('Unexpected error', exc_info=True)
            raise
    except:
        try:
            browser.quit()
        except:
            pass
        try:
            ids_file.close()
        except:
            pass
        try:
            results_file.close()
        except:
            pass
        try:
            # suppression de ids.tmp pour que le script php reprenne la main
            # création de ids.todo pour indiquer les scripts restant à scrapper
            os.system("tail -n +{} ids.tmp > ids.todo".format(last_scrapped))
            os.remove('ids.tmp')
        except:
            pass
